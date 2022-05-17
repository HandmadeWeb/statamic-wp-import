<?php

namespace Jezzdk\StatamicWpImport\Helpers;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statamic\Assets\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Term;
use Statamic\Facades\Taxonomy;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Migrator
{
    /**
     * The migration array
     *
     * @var array
     */
    private $migration;

    /**
     * The summary array
     *
     * @var array
     */
    private $summary;

    /**
     * Perform the migration
     *
     * @param $migration
     * @param $summary
     */
    public function migrate($migration, $summary)
    {
        $this->migration = $this->prepareMigration($migration);
        $this->summary = $summary;

        $this->createTaxonomies();
        $this->createTaxonomyTerms();

        $this->createCollections();
        $this->createEntries();

        $this->createPages();

        Stache::clear();
    }

    /**
     * Prepare the migration
     *
     * @param array $migration
     * @return array
     */
    private function prepareMigration($migration)
    {
        $migration['pages'] = collect(
            $this->sortDeepest(
                array_get($migration, 'pages', [])->all()
            )
        );

        return $migration;
    }

    /**
     * Sort an array by folder depth (amount of slashes)
     *
     * @param  array $arr An array with paths for keys
     * @return array      The sorted array
     */
    private function sortDeepest($arr)
    {
        uksort($arr, function ($a, $b) {
            return (substr_count($a, '/') >= substr_count($b, '/')) ? 1 : -1;
        });

        // Move homepage to top
        if (isset($arr['/'])) {
            $arr = ['/' => $arr['/']] + $arr;
        }

        return $arr;
    }

    /**
     * Create taxonomies
     *
     * @return void
     */
    private function createTaxonomies()
    {
        foreach (array_get($this->migration, 'taxonomies', []) as $taxonomy_slug => $taxonomy_data) {
            $taxonomy = Taxonomy::findByHandle($taxonomy_slug);

            if (!$taxonomy) {
                $taxonomy = Taxonomy::make($taxonomy_slug);
            }

            // foreach ($taxonomy_data as $key => $value) {
            //     $taxonomy->set($key, $value);
            // }

            $taxonomy->save();
        }
    }

    /**
     * Create taxonomy terms
     *
     * @return void
     */
    private function createTaxonomyTerms()
    {
        foreach (array_get($this->migration, 'terms', []) as $taxonomy_slug => $terms) {
            foreach ($terms as $term_slug => $term_data) {
                // Skip if this term was not checked in the summary.
                if (!$this->summary['taxonomies'][$taxonomy_slug]['terms'][$term_slug]['_checked']) {
                    continue;
                }

                $term = Term::findBySlug($term_slug, $taxonomy_slug);

                if (!$term) {
                    $term = Term::make($term_slug)->taxonomy($taxonomy_slug);
                }

                foreach ($term_data as $key => $value) {

                    if ($key === 'featured_image_url') {
                        if(isset($value) && is_string($value))  {
                            if (config('statamic-wp-import.download_images')) {
                                $asset = $this->downloadAsset($value ?? '', 'taxonomies', $term_slug);

                                if ($asset) {
                                    $term->set('thumbnail', $asset->path());
                                }
                            }
                        }
                    } else {
                        $term->set($key, $value);
                    }

                }

                $term->save();
            }
        }
    }

    /**
     * Create collections
     *
     * @return void
     */
    private function createCollections()
    {
        foreach (array_get($this->migration, 'collections', []) as $handle => $data) {
            $collection = Collection::findByHandle($handle);

            if (!$collection) {
                $collection = Collection::make($handle);
            }

            $collection->dated(true);
            $collection->sortDirection('desc');
            $collection->futureDateBehavior('private');
            $collection->pastDateBehavior('public');
            $collection->save();
        }
    }

    /**
     * Create entries
     *
     * @return void
     */
    private function createEntries()
    {
        foreach ($this->migration['entries'] as $collection => $entries) {
            foreach ($entries as $slug => $meta) {
                // Skip if this entry was not checked in the summary.
                if (!$this->summary['collections'][$collection]['entries'][$slug]['_checked']) {
                    continue;
                }

                $entry = Entry::query()->where('collection', $collection)->where('slug', $slug)->first();

                if (!$entry) {
                    $entry = Entry::make()->collection($collection)->slug($slug);
                }

                $entry->date($meta['order']);

                array_set($meta, 'data.slug', $slug);

                foreach ($meta['data'] as $key => $value) {

                    if ($key === 'taxonomies' && is_array($value)) {
                        foreach($value as $taxonomy => $terms) {
                            $entry->set($taxonomy, $terms);
                        }
                    } else if($key === 'content') {

                        // Get all image URL's from the content
                        preg_match_all('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $value, $match);

                        var_dump($match);

                        foreach($match[0] as $url) {
                            if (!str_contains($url, 'uploads')) return;
                            $asset = $this->downloadAsset($url ?? '', $collection, $slug);
                            if ($asset) {
                                $value = str_replace($url, $asset->url(), $value);
                            }
                        }

                        $entry->set($key, $value);
                    } else if(!str_contains($key, 'gallery_slider') && !str_contains($key, 'image') && !str_contains($key, 'map_shortcode') && !str_contains($key, 'featured_image_url')) {
                        $entry->set($key, $value);
                    }

                }

                if (config('statamic-wp-import.download_images')) {
                    $asset = $this->downloadAsset($meta['data']['featured_image_url'] ?? '', $collection, $slug);

                    if ($asset) {
                        $entry->set('thumbnail', $asset->path());
                    }
                }

                $entry->save();
                // $entry->update();
            }
        }
    }

    /**
     * Create pages
     *
     * @return void
     */
    private function createPages()
    {
        foreach ($this->migration['pages'] as $url => $meta) {
            // Skip if this page was not checked in the summary.
            if (!$this->summary['pages'][$url]['_checked']) {
                continue;
            }

            $urlParts = explode('/', $url);
            $slug = array_pop($urlParts);

            $page = Entry::query()->where('collection', 'pages')->where('slug', $slug)->first();

            if (!$page) {
                $page = Entry::make()->collection('pages')->slug($slug);
            }

            array_set($meta, 'data.slug', $slug);

            foreach ($meta['data'] as $key => $value) {
                $page->set($key, $value);
            }

            if (config('statamic-wp-import.download_images')) {
                $asset = $this->downloadAsset($meta['data']['featured_image_url'] ?? '', 'pages', $slug);

                if ($asset) {
                    $page->set('thumbnail', $asset->path());
                }
            }

            $page->save();
        }
    }

    /**
     * Create an asset from a URL
     *
     * @param string|null $url
     * @return Asset|bool
     */
    private function downloadAsset(string $url = null, string $collection, string $slug): Asset|bool
    {
        if (!$url) {
            return false;
        }

        try {
            $image = Http::retry(3, 500)->get($url)->body();

            $originalImageName = basename($url);

            Storage::put($tempFile = 'temp', $image);

            $assetContainer = AssetContainer::findByHandle(config('statamic-wp-import.assets_container'));

            $asset = $assetContainer->makeAsset("{$collection}/{$slug}/{$originalImageName}");

            if ($asset->exists() && config('statamic-wp-import.skip_existing_images')) {
                return $asset;
            }

            if ($asset->exists() && config('statamic-wp-import.overwrite_images')) {
                $asset->delete();
            }

            $asset->upload(
                new UploadedFile(
                    Storage::path($tempFile),
                    $originalImageName,
                )
            );

            $asset->save();

            return $asset;
        } catch (Exception $e) {
            logger('Image download failed: ' . $e->getMessage());
            return false;
        }
    }
}

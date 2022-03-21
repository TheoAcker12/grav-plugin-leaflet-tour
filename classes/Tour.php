<?php

namespace Grav\Plugin\LeafletTour;

use Grav\Common\Grav;
use RocketTheme\Toolbox\File\MarkdownFile;

class Tour {

    /**
     * List of tile servers provided by this plugin. Will change as leaflet providers is implemented.
     *  - key => [options?, attribution, type?, select, name?]
     */
    const TILE_SERVERS = [
        'stamenWatercolor' => [
            'type'=>'stamen',
            'select'=>'Stamen Watercolor',
            'name'=>'watercolor',
            'attribution'=>'Map tiles by <a href="http://stamen.com">Stamen Design</a>, under <a href="http://creativecommons.org/licenses/by/3.0">CC BY 3.0</a>. Data by <a href="http://openstreetmap.org">OpenStreetMap</a>, under <a href="http://creativecommons.org/licenses/by-sa/3.0">CC BY SA</a>.',
        ],
        'stamenToner' => [
            'type' => 'stamen',
            'select' => 'Stamen Toner',
            'name' => 'toner',
            'attribution' => 'Map tiles by <a href="http://stamen.com">Stamen Design</a>, under <a href="http://creativecommons.org/licenses/by/3.0">CC BY 3.0</a>. Data by <a href="http://openstreetmap.org">OpenStreetMap</a>, under <a href="http://www.openstreetmap.org/copyright">ODbL</a>.',
        ],
        'stamenTerrain' => [
            'type' => 'stamen',
            'select' => 'Stamen Terrain',
            'name' => 'terrain',
            'attribution' => 'Map tiles by <a href="http://stamen.com">Stamen Design</a>, under <a href="http://creativecommons.org/licenses/by/3.0">CC BY 3.0</a>. Data by <a href="http://openstreetmap.org">OpenStreetMap</a>, under <a href="http://www.openstreetmap.org/copyright">ODbL</a>.',
        ],
    ];
    /**
     * tour.md file, never modified
     */
    private ?MarkdownFile $file = null;

    // properties from yaml
    /**
     * Unique tour identifier generated by plugin on initial tour save, set in constructor and never modified
     */
    private ?string $id = null;
    private ?string $title = null;
    private array $datasets = [];

    // calculated and stored properties
    /**
     * [$id => Feature] for features from all datasets in the tour - set when needed, cleared with features when tour or datasets change
     */
    private ?array $all_features = null;
    /**
     * list of feature ids for all included features in tour (from datasets with include_all and not hidden or included in tour features list) - set when needed, cleared with features when tour or datasets change
     */
    private ?array $included_features = null;
    /**
     * list of dataset ids of all datasets with at least one feature included in tour - cleared when features are cleared, generated when included_features is generated
     */
    private ?array $included_datasets = null;
    
    private function __construct(array $options) {
        foreach ($options as $key => $value) {
            $this->$key = $value;
        }
        // make datasets a bit nicer
        $this->datasets = array_column($this->datasets ?? [], null, 'id');
    }
    public static function fromFile(MarkdownFile $file): ?Tour {
        if ($file->exists()) {
            $options = (array)($file->header());
            $options['file'] = $file;
            return new Tour($options);
        }
        else return null;
    }
    public static function fromArray(array $options): Tour {
        return new Tour($options);
    }

    // object methods

    /**
     * @return Tour An identical copy of the tour
     * 
     * Feature and Dataset objects are only referenced, never modified, so would be counterproductive to create deep copies
     */
    public function clone(): Tour {
        $options = [];
        foreach (get_object_vars($this) as $key => $value) {
            $options[$key] = $value;
        }
        return new Tour($options);
    }
    /**
     * Takes yaml update array from tour header and validates it. Also clears some options that will need to be regenerated.
     */
    public function update(array $yaml): array {
        // order matters for a few things
        if ($datasets = $yaml['datasets']) $this->setDatasets($datasets);
        // remove a few properties that should not be included in the yaml, just in case, as well as properties previously dealt with
        $yaml = array_diff_key($yaml, array_flip(['file', 'all_features', 'included_features', 'included_datasets', 'datasets']));
        foreach ($yaml as $key => $value) {
            switch ($key) {
                // TODO: a few properties with specific setters
                case 'id':
                    $this->setId($value);
                    break;
                // everything else
                default:
                    $this->$key = $value;
            }
        }
        return array_merge($yaml, $this->asYaml());
    }
    /**
     * Most values from the tour header are stored as they were retrived, and can be directly returned. datasets and features are indexed by id, so only array values should be returned
     * @return array yaml
     */
    public function asYaml(): array {
        $yaml = [];
        foreach (['id', 'title'] as $property) {
            if ($value = $this->$property) $yaml[$property] = $value;
        }
        $yaml['datasets'] = array_values($this->datasets);
        return $yaml;
    }
    /**
     * Called after a dataset page has been deleted.
     * @return bool True if dataset was in tour and had to be removed
     */
    public function removeDataset(string $id): bool {
        if ($this->getDatasets()[$id]) {
            unset($this->datasets[$id]);
            $this->updateFeatures();
            $this->save();
            return true;
        }
        else return false;
    }
    /**
     * Called after a dataset page has been updated
     * @return bool True if dataset is in tour
     */
    public function updateDataset(string $id): bool {
        if ($this->getDatasets()[$id]) {
            $this->updateFeatures();
            $this->save();
            return true;
        }
        else return false;
    }
    public function save(): void {
        if ($this->file) {
            $this->file->header($this->asYaml());
            $this->file->save();
        }
    }

    // private methods

    private function updateFeatures(?array $features = null): void {
        $this->clearFeatures();
    }
    /**
     * clears all stored values relating to datasets and features (too interconnected to bother with separate methods)
     */
    private function clearFeatures(): void {
        $this->included_features = $this->all_features = $this->included_datasets = null;
    }

    // setters
    /**
     * @param string $id Sets $this->id if not yet set
     */
    public function setId(string $id): void {
        $this->id ??= $id;
    }
    public function setFile(MarkdownFile $file): void {
        $this->file ??= $file;
    }
    private function setDatasets(array $yaml) {
        $datasets = [];
        foreach ($yaml as $dataset_yaml) {
            $id = $dataset_yaml['id'];
            if (LeafletTour::getDatasets()[$id]) {
                $datasets[$id] = $dataset_yaml;
            }
        }
        $this->datasets = $datasets;
        $this->clearFeatures();
    }

    // getters
    /**
     * @return null|MarkdownFile $this->file
     */
    public function getFile(): ?MarkdownFile {
        return $this->file;
    }
    /**
     * @return null|string $this->id (should always be set)
     */
    public function getId(): ?string {
        return $this->id;
    }
    public function getTitle(): ?string {
        return $this->title;
    }
    public function getDatasets(): array {
        return $this->datasets;
    }
    // note: most stored file values do not require separate getters

    // calculated and stored getters

    /**
     * Sets $this->all_features if not set, then returns it.
     * does not generate $this->included_features
     * @return array [$id => Feature]
     */
    public function getAllFeatures(): array {
        if (!$this->all_features) {
            $this->all_features = [];
            foreach (array_keys($this->getDatasets()) as $id) {
                if ($dataset = LeafletTour::getDatasets()[$id]) {
                    $this->all_features = array_merge($this->all_features, $dataset->getFeatures());
                }
            }
        }
        return $this->all_features;
    }
    /**
     * Sets $this->included_features (and $this->included_datasets) if not set, then returns it.
     * @return array [$id, ...]
     */
    public function getIncludedFeatures(): array {
        if (!$this->included_features) {
            $this->included_features = [];
            $this->included_datasets = [];
            foreach ($this->datasets as $dataset_id => $header_dataset) {
                if ($dataset = LeafletTour::getDatasets()[$dataset_id]) {
                    // check for included features
                    $features = array_keys($dataset->getFeatures());
                    // if features, also add included dataset
                    if (!empty($features)) {
                        $this->included_features = array_merge($this->included_features, $features);
                        $this->included_datasets[] = $dataset_id;
                    }
                }
            }
        }
        return $this->included_features;
    }
    private function getIncludedDatasets(): array {
        if (!$this->included_datasets) $this->getIncludedFeatures();
        return $this->included_datasets;
    }
    
    // other calculated getters
    public function getTileServer():array {
        return array_values(self::TILE_SERVERS)[0];
    }
    public function getTourData(): array {
        $data = [
            'tile_server' => $this->getTileServer(),
        ];
        return $data;
    }
    public function getDatasetData(): array {
        $datasets = [];
        foreach ($this->getIncludedDatasets() as $id) {
            $dataset = LeafletTour::getDatasets()[$id];
            $info = [
                'id' => $id,
                'features' => [],
            ];
            if ($dataset->getType() === 'Point') {
                $info['icon'] = $dataset->getIcon();
            }
            $datasets[$id] = $info;
        }
        return $datasets;
    }
    public function getFeatureData(): array {
        $features = [];
        foreach ($this->getIncludedFeatures() as $id) {
            $feature = $this->getAllFeatures()[$id];
            $features[$id] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => $feature->getType(),
                    'coordinates' => $feature->getCoordinatesJson(),
                ],
                'properties' => [
                    'id' => $id,
                    'name' => $feature->getName(),
                    'dataset' => $feature->getDataset()->getId(),
                ],
            ];
        }
        return $features;
    }
}
?>
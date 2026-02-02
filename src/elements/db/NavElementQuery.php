<?php
namespace fortytwostudio\websitedocumentation\elements\db;

use fortytwostudio\websitedocumentation\elements\NavElement;
use fortytwostudio\websitedocumentation\models\Navigation as NavigationModel;

use Craft;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;

class NavElementQuery extends ElementQuery
{
    // Properties
    // =========================================================================

    public mixed $id = null;
    public mixed $elementId = null;
    public mixed $siteId = null;
    public mixed $menuId = null;
    public mixed $enabled = true;
    public mixed $type = null;
    public mixed $element = null;
    public mixed $handle = null;
    public mixed $hasUrl = false;


    // Public Methods
    // =========================================================================

    public function init(): void
    {
        if (!isset($this->withStructure)) {
            $this->withStructure = true;
        }

        parent::init();
    }

    public function elementId($value): static
    {
        $this->elementId = $value;
        return $this;
    }

    public function elementSiteId($value): static
    {
        $this->slug = $value;
        return $this;
    }

    public function menuId($value): static
    {
        $this->menuId = $value;
        return $this;
    }

    public function navHandle($value): static
    {
        $this->handle = $value;
        return $this;
    }

    public function nav($value): static
    {
        if ($value instanceof NavigationModel) {
            $this->menuId = $value->id;
        } else if ($value !== null) {
            $this->menuId = (new Query())
                ->select(['id'])
                ->from('{{%documentation_navigations}}')
                ->where(Db::parseParam('handle', $value))
                ->column();
        } else {
            $this->menuId = null;
        }

        return $this;
    }

    public function type($value): static
    {
        $this->type = $value;
        return $this;
    }

    public function element($value): static
    {
        $this->element = $value;
        return $this;
    }

    public function handle($value): static
    {
        $this->handle = $value;
        return $this;
    }

    public function hasUrl(bool $value = false): static
    {
        $this->hasUrl = $value;
        return $this;
    }

    // We set the active state on each node, however it gets trickier when trying to do things like settings the active
    // state when a child is active, which involves firing off additional element queries for each node's children,
    // which quickly blow out queries. So instead, do this when the elements are populated
    public function populate($rows): array
    {
        // Let the parent class handle this like normal
        $rows = parent::populate($rows);

        // Store all processed items by their ID, we need to lookup parents later
        $processedRows = ArrayHelper::index($rows, 'id');

        foreach ($rows as $row) {
            // If the current node is active, and it has a parent, set its active state
            if (is_a($row, NavElement::class) && $row->active) {
                $ancestors = $row->ancestors->all();

                foreach ($ancestors as $ancestor) {
                    if (isset($processedRows[$ancestor->id])) {
                        $processedRows[$ancestor->id]->isActive = true;
                    }
                }
            }
        }

        return $rows;
    }


    // Protected Methods
    // =========================================================================

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('documentation_navigation_elements');
        $this->subQuery->innerJoin('{{%documentation_navigations}} documentation_navigations', '[[documentation_navigation_elements.menuId]] = [[documentation_navigations.id]]');

        $this->query->select([
            'documentation_navigation_elements.id',
            'documentation_navigation_elements.elementId',
            'documentation_navigation_elements.menuId',
            'documentation_navigation_elements.url',
            'documentation_navigation_elements.type',

            // Join the element's uri onto the same query
            'element_item_sites.uri AS elementUrl',
        ]);

        if ($this->id) {
            $this->subQuery->andWhere(Db::parseParam('documentation_navigation_elements.id', $this->id));
        }

        if ($this->elementId) {
            $this->subQuery->andWhere(Db::parseParam('documentation_navigation_elements.elementId', $this->elementId));
        }

        if ($this->menuId) {
            $this->subQuery->andWhere(Db::parseParam('documentation_navigation_elements.menuId', $this->menuId));
        }
        
        // Site ID
		if ($this->siteId) {
			$parentNav = (new Query())
				->select(['dn.id'])
				->from(['dn' => '{{%documentation_navigations}}'])
				->where(Db::parseParam('dn.siteId', $this->siteId))
				->scalar();

            $this->subQuery->andWhere(Db::parseParam('documentation_navigation_elements.menuId', $parentNav));
        }

        if ($this->type) {
            $this->subQuery->andWhere(Db::parseParam('documentation_navigation_elements.type', $this->type));
        }

        if ($this->handle) {
            $this->subQuery->andWhere(Db::parseParam('documentation_navigations.handle', $this->handle));
        }

        if ($this->hasUrl) {
            $this->subQuery->andWhere(['or', ['not', ['documentation_navigation_elements.elementId' => null, 'documentation_navigation_elements.elementId' => '']], ['not', ['documentation_navigation_elements.url' => null, 'documentation_navigation_elements.url' => '']]]);
        }

        return parent::beforePrepare();
    }

    protected function afterPrepare(): bool
    {
        if (Craft::$app->getDb()->getIsMysql()) {
            $sql = 'CAST([[elements_sites.slug]] AS UNSIGNED)';
        } else {
            $sql = 'CAST([[elements_sites.slug]] AS INTEGER)';
        }

        // Join the element sites table (again) for the linked element
        $this->query->leftJoin('{{%elements_sites}} element_item_sites', '[[documentation_navigation_elements.elementId]] = [[element_item_sites.elementId]] AND ' . $sql . ' = [[element_item_sites.siteId]]');

        return parent::afterPrepare();
    }
}

<?php
namespace fortytwostudio\websitedocumentation\elements\db;

use fortytwostudio\websitedocumentation\elements\GuideEntry;

use Craft;
use craft\db\Table;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;

class GuideQuery extends ElementQuery
{
	// Properties
	// =========================================================================

	public mixed $id = null;
	public mixed $typeId = null;
	public mixed $postDate = null;
	public mixed $siteId = null;


	// Public Methods
	// =========================================================================

	public function init(): void
	{
		if (!isset($this->withStructure)) {
			$this->withStructure = true;
		}

		parent::init();
	}

	/**
	 * @inheritdoc
	 */
	public function __construct($elementType, array $config = [])
	{
		parent::__construct($elementType, $config);
	}

	public function postDate(mixed $value): static
	{
		$this->postDate = $value;
		return $this;
	}

	public function siteId($value): static
	{
		$this->siteId = $value;
		return $this;
	}

	protected function beforePrepare(): bool
	{
		$entryType = Craft::$app->getEntries()
			->getEntryTypeByHandle('websiteDocumentationContent');
	
		if (!$entryType) {
			return false;
		}
	
		$this->typeId = $entryType->id;
	
		$this->joinElementTable('documentation_guide_entries');
	
		$this->query->select([
			'documentation_guide_entries.id',
			'documentation_guide_entries.structureId',
			'documentation_guide_entries.parentId',
			'documentation_guide_entries.siteId',
		]);
	
		if ($this->structureId) {
			$this->subQuery->andWhere(
				Db::parseParam(
					'documentation_guide_entries.structureId',
					$this->structureId
				)
			);
		}
	
		if ($this->siteId) {
			$this->subQuery->andWhere(
				Db::parseParam(
					'documentation_guide_entries.siteId',
					$this->siteId
				)
			);
		}
	
		return parent::beforePrepare();
	}

	/**
	 * @inheritdoc
	 */
	protected function fieldLayouts(): array
	{
		$this->_normalizeTypeId();

		if ($this->typeId) {
			$fieldLayouts = [];
			$sectionsService = Craft::$app->getEntries();

			foreach ($this->typeId as $entryTypeId) {
				$entryType = $sectionsService->getEntryTypeById($entryTypeId);
				if ($entryType) {
					$fieldLayouts[] = $entryType->getFieldLayout();
				}
			}
			return $fieldLayouts;
		}
	}

	/**
	 * Normalizes the typeId param to an array of IDs or null
	 *
	 * @throws InvalidConfigException
	 */
	private function _normalizeTypeId(): void
	{
		if (empty($this->typeId)) {
			$this->typeId = is_array($this->typeId) ? [] : null;
		} elseif (is_numeric($this->typeId)) {
			$this->typeId = [$this->typeId];
		} elseif (!is_array($this->typeId) || !ArrayHelper::isNumeric($this->typeId)) {
			$this->typeId = (new Query())
				->select(['id'])
				->from([Table::ENTRYTYPES])
				->where(Db::parseNumericParam('id', $this->typeId))
				->column();
		}
	}


}

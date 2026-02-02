<?php
namespace fortytwostudio\websitedocumentation\elements;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;
use fortytwostudio\websitedocumentation\elements\GuideEntry;
use fortytwostudio\websitedocumentation\records\GuideEntry as GuideEntryRecord;
use fortytwostudio\websitedocumentation\elements\db\GuideQuery;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Duplicate;
use craft\elements\actions\Edit;
use craft\elements\actions\MoveToSection;
use craft\elements\User;
use craft\events\ElementCriteriaEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\records\StructureElement;
use craft\services\Structures;

use yii\base\InvalidConfigException;
use DateTime;

class GuideEntry extends Element
{

	// Constants
	// =========================================================================
	public const STATUS_LIVE = 'live';
	public const STATUS_DISABLED = 'disabled';

	// Properties
	// =========================================================================

	/**
	 * @var ?int The ID of the entry type this entry is for.
	 */
	public ?int $typeId = null;

	/**
	 * @var ?string The UID of the structure.
	 */
	public ?string $structureUid = null;

	/**
	 * @var ?string The ID of the structure.
	 */
	public ?int $structureId = null;

	/**
	 * @var ?int The ID of the site.
	 */
	public ?int $siteId = null;

	/**
	 * @var ?int The ID of the parent.
	 */
	public ?int $_parentId = null;

	/**
	 * @var DateTime|null Post date
	 */
	public ?DateTime $postDate = null;

	/**
	 * @var DateTime|null Expiry date
	 */
	public ?DateTime $expiryDate = null;

	/**
	 * @var ?EntryType
	 */
	private ?EntryType $_entryType = null;

	// Static
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public static function displayName(): string
	{
		return Craft::t('app', 'Guide Entry');
	}

	/**
	 * @inheritdoc
	 */
	public static function lowerDisplayName(): string
	{
		return Craft::t('app', 'guide entry');
	}

	/**
	 * @inheritdoc
	 */
	public static function pluralDisplayName(): string
	{
		return Craft::t('app', 'Guide Entries');
	}

	/**
	 * @inheritdoc
	 */
	public static function pluralLowerDisplayName(): string
	{
		return Craft::t('app', 'guide entries');
	}

	/**
	 * @inheritdoc
	 */
	public static function refHandle(): ?string
	{
		return 'guideEntries';
	}

	/**
	 * @inheritdoc
	 */
	public static function hasDrafts(): bool
	{
		return false;
	}

	/**
	 * @inheritdoc
	 */
	public static function trackChanges(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function canView(User $user): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function canSave(User $user): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function canDelete(User $user): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public static function hasTitles(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public static function hasUris(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public static function isLocalized(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public static function hasStatuses(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public static function statuses(): array
	{
		return [
			self::STATUS_LIVE => Craft::t('app', 'Live'),
			self::STATUS_DISABLED => Craft::t('app', 'Disabled'),
		];
	}

	/**
	 * @inheritdoc
	 * @return EntryQuery The newly created [[GuideQuery]] instance.
	 */
	public static function find(): GuideQuery
	{
		return new GuideQuery(static::class);
	}

	// Public
	// =========================================================================

	/**
	 * @inheritdoc
	 * @since 3.5.0
	 */
	public function init(): void
	{
		parent::init();

		$entryType = Craft::$app->getEntries()->getEntryTypeByHandle('websiteDocumentationContent');
		$this->_entryType = Craft::$app->getEntries()->getEntryTypeByHandle('websiteDocumentationContent');
		$this->typeId = $entryType->id;
		$this->structureUid = WebsiteDocumentation::$settings->structureUid;

		if ($this->structureUid) {
			$structure = Craft::$app->structures->getStructureByUid($this->structureUid);
   			$this->structureId = $structure?->id;
		}
	}

	/**
	 * Sets the entry authors’ IDs.
	 *
	 * @param User[]|int[]|string|int|null $authorIds
	 * @since 5.0.0
	 */
	public function setAuthorIds(array|string|int|null $authorIds): void
	{
		$authorIds = $this->normalizeAuthorIds($authorIds);

		if (isset($this->_authorIds)) {
			if ($authorIds === $this->_authorIds) {
				return;
			}

			if (!isset($this->_oldAuthorIds)) {
				// remember the old IDs so we know if this has been modified
				$this->_oldAuthorIds = $this->_authorIds;
			}
		}

		$this->_authorIds = $authorIds;
		$this->_authors = null;
	}

	private function normalizeAuthorIds(array|string|int|null $authorIds): array
	{
		if ($authorIds === '' || $authorIds === null) {
			return [];
		}

		// make sure we're working with an array
		if (!is_array($authorIds)) {
			$authorIds = ArrayHelper::toArray($authorIds);
		}

		return array_map(fn($id) => (int)$id, $authorIds);
	}

	/**
	 * @inheritdoc
	 */
	public function getCpEditUrl(): ?string
	{
		$editUrl = "website-documentation/guides/$this->id";

		if ($this->draftId) {
			$editUrl = "$editUrl?draft=$this->draftId";
		};

		return UrlHelper::cpUrl($editUrl);
	}

	/**
	 * @inheritdoc
	 */
	public function getFieldLayout(): ?FieldLayout
	{
		if (($fieldLayout = parent::getFieldLayout()) !== null) {
			return $this->_fieldLayoutWithoutEntryTitleField($fieldLayout);
		}
		try {
			$entryType = $this->getEntryType();
		} catch (InvalidConfigException) {
			// The entry type was probably deleted
			return null;
		}

		return $entryType !== null
			? $this->_fieldLayoutWithoutEntryTitleField($entryType->getFieldLayout())
			: null;
	}

	/**
	 * @inheritdoc
	 */
	public function afterSave(bool $isNew): void
	{
		// Add Data to database
		if (!$this->propagating) {
			if (!$isNew) {
				$record = GuideEntryRecord::findOne($this->id);

				if (!$record) {
					$record = new GuideEntryRecord();
					$record->id = (int)$this->id;
				}

			} else {
				$record = new GuideEntryRecord();
				$record->id = (int)$this->id;
				$record->postDate = new DateTime();
			}

			$record->structureId = $this->structureId;
			$record->parentId = $this->parentId;
			$record->typeId = (int)$this->typeId;
			$record->siteId = (int)$this->siteId;

			$dirtyAttributes = array_keys($record->getDirtyAttributes());
			$record->save(false);

			$this->setDirtyAttributes($dirtyAttributes);
		};

		// Save Parent
		$this->_parentId = $this->parentId;

		if ($this->hasNewParent()) {
			$this->_placeInStructure($isNew, $this->structureId);
		}

		// Add into structure element
		$structureElement = StructureElement::findOne([
			'structureId' => $this->structureId,
			'elementId' => $this->id,
		]);

		if (!$structureElement) {
			$structuresService = Craft::$app->getStructures();
			$mode = $isNew ? Structures::MODE_INSERT : Structures::MODE_AUTO;
			$structuresService->appendToRoot($this->structureId, $this, $mode);
		}

		parent::afterSave($isNew);
	}

	/**
	 * @inheritdoc
	 */
	public function afterDelete(): void
	{
		parent::afterDelete();
	}

	/**
	 * @inheritdoc
	 */
	public function metaFieldsHtml(bool $static): string
	{
		$fields = [];

		// Title Field
		$fields[] = Cp::textFieldHtml([
			'label' => Craft::t('websitedocumentation', 'Guide Title'),
			'id' => 'title',
			'name' => 'title',
			'autocorrect' => false,
			'autocapitalize' => false,
			'value' => $this->title,
			'disabled' => $static,
			'errors' => $this->getErrors('title'),
		]);

		// Parent Field
		$parent = self::find()
			->siteId($this->siteId)
			->ancestorOf(
				$this->lft ?
					$this :
					($this->getIsCanonical() ?
						$this->id : $this->getCanonical(true)
					)
			)
			->one();

		$fields[] = Cp::elementSelectFieldHtml([
			'label' => Craft::t('app', 'Parent'),
			'id' => 'parentId',
			'name' => 'parentId',
			'elementType' => self::class,
			'selectionLabel' => Craft::t('app', 'Choose'),
			'limit' => 1,
			'elements' => $parent ? [$parent] : [],
			'criteria' => $this->_parentOptionCriteria($this->structureId),
			'disabled' => $static,
			'describedBy' => 'parentId-label',
			'errors' => $this->getErrors('parentId'),
		]);

		$fields[] = parent::metaFieldsHtml($static);

		return implode("\n", $fields);
	}

	/**
	 * @inheritdoc
	 */
	public function canCreateDrafts(User $user): bool
	{
		return false;
	}

	/**
	 * @inheritdoc
	 */
	public function getPostEditUrl(): ?string
	{
		return UrlHelper::cpUrl('website-documentation/guides');
	}

	public function getEntryType(): ?EntryType
	{
		return $this->_entryType;
	}

	/**
	 * @inheritdoc
	 */
	public function afterMoveInStructure(int $structureId): void
	{
		Craft::$app->getElements()->updateElementSlugAndUri($this, true, true, true);

		// If this is the canonical entry, update its drafts
		if ($this->getIsCanonical()) {
			/** @var self[] $drafts */
			$drafts = self::find()
				->draftOf($this)
				->status(null)
				->site('*')
				->unique()
				->all();
			$structuresService = Craft::$app->getStructures();
			$lastElement = $this;

			foreach ($drafts as $draft) {
				$structuresService->moveAfter($section->structureId, $draft, $lastElement);
				$lastElement = $draft;
			}
		}

		parent::afterMoveInStructure($structureId);
	}

	/**
	 * @inheritdoc
	 */
	public function getAltActions(): array
	{
		$isUnpublishedDraft = $this->getIsUnpublishedDraft();
		$elementsService = Craft::$app->getElements();
		$canSaveCanonical = $elementsService->canSaveCanonical($this);

		$altActions = [
			[
				'label' => $isUnpublishedDraft && $canSaveCanonical
					? Craft::t('app', 'Create and continue editing')
					: Craft::t('app', 'Save and continue editing'),
				'redirect' => '{cpEditUrl}',
				'shortcut' => true,
				'retainScroll' => true,
				'eventData' => ['autosave' => false],
			],
		];

		$newElement = $this->createAnother();
		if ($newElement && $elementsService->canSave($newElement)) {
			$altActions[] = [
				'label' => $isUnpublishedDraft && $canSaveCanonical
					? Craft::t('app', 'Create and add another')
					: Craft::t('app', 'Save and add another'),
				'shortcut' => true,
				'shift' => true,
				'eventData' => ['autosave' => false],
				'params' => ['addAnother' => 1],
			];
		}

		// Fire a 'defineAltActions' event
		if ($this->hasEventHandlers(self::EVENT_DEFINE_ALT_ACTIONS)) {
			$event = new DefineAltActionsEvent([
				'altActions' => $altActions,
			]);
			$this->trigger(self::EVENT_DEFINE_ALT_ACTIONS, $event);
			return $event->altActions;
		}

		return $altActions;
	}

	public function getSupportedSites(): array
	{
		$siteIds = [$this->siteId];

		return $siteIds;
	}

	/**
	 * @inheritdoc
	 */
	public function createAnother(): ?self
	{
		/** @var self $entry */
		$entry = Craft::createObject([
			'class' => self::class,
			'structureId' => $this->structureId,
			'parentId' => $this->parentId,
			'typeId' => $this->typeId,
			'siteId' => $this->siteId,
		]);

		$enabled = true;

		if (Craft::$app->getIsMultiSite() && count($entry->getSupportedSites()) > 1) {
			$entry->enabled = true;
			$entry->setEnabledForSite($enabled);
		} else {
			$entry->enabled = $enabled;
			$entry->setEnabledForSite(true);
		}

		// Parent
		$entry->setParentId($this->getParentId());

		return $entry;
	}

	// Private
	// =========================================================================

	/**
	 * Remove the EntryTitleField as this is only allowed on entries
	 */
	private function _fieldLayoutWithoutEntryTitleField(FieldLayout $fieldLayout): FieldLayout
	{
		foreach ($fieldLayout->getTabs() as $tab) {
			$tab->setElements(array_filter($tab->getElements(), fn($element) => !$element instanceof EntryTitleField));
		}

		return $fieldLayout;
	}

	private function _placeInStructure(bool $isNew, int $structureId): void
	{
		$parentId = $this->_parentId;
		$structuresService = Craft::$app->getStructures();

		// If this is a provisional draft and its new parent matches the canonical entry’s, just drop it from the structure
		if ($this->isProvisionalDraft) {
			$canonicalParentId = self::find()
				->select(['elements.id'])
				->ancestorOf($this->getCanonicalId())
				->ancestorDist(1)
				->status(null)
				->site('*')
				->unique()
				->scalar();

			if ($parentId == $canonicalParentId) {
				$structuresService->remove($this->structureId, $this);
				return;
			}
		}

		$mode = $isNew ? Structures::MODE_INSERT : Structures::MODE_AUTO;

		if (!$parentId) {
			$structuresService->appendToRoot($structureId, $this, $mode);
		} else {
			$structuresService->append($structureId, $this, $this->getParent(), $mode);
		};
	}

	private function _parentOptionCriteria(int $structure): array
	{
		$parentOptionCriteria = [
			'siteId' => $this->siteId,
			'structureId' => $structure,
		];

		// Prevent the current entry, or any of its descendants, from being selected as a parent
		if ($this->id) {
			$excludeIds = self::find()
				->descendantOf($this)
				->drafts(null)
				->draftOf(false)
				->status(null)
				->ids();
			$excludeIds[] = $this->getCanonicalId();
			$parentOptionCriteria['id'] = array_merge(['not'], $excludeIds);
		}

		return $parentOptionCriteria;
	}

	// Protected
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	protected static function defineSources(string $context): array
	{
		$sources = [];

		$settings = WebsiteDocumentation::$plugin->getSettings();
		$structureId = Craft::$app->structures->getStructureByUid($settings->structureUid)?->id;

		$sources[] = [
			'key' => 'guides',
			'label' => Craft::t('site', 'CMS Guides'),
			'data' => [
				'type' => 'structure',
			],
			'structureId' => $structureId,
			'structureEditable' => true,
		];

		return $sources;
	}

	protected static function defineSortOptions(): array
	{
		return [
			'title' => Craft::t('app', 'Title'),
		];
	}

	protected static function defineTableAttributes(): array
	{
		return [
			'status' => Craft::t('app', 'Status'),
		];
	}

	protected static function defineActions(string $source = null): array
	{
		$actions = [];

		// Duplicate
		$actions[] = Craft::$app->getElements()->createAction([
			'type' => Duplicate::class,
		]);
		$actions[] = Craft::$app->getElements()->createAction([
			'type' => Duplicate::class,
			'deep' => true,
		]);

		// Edit
		$actions[] = Craft::$app->getElements()->createAction([
			'type' => Edit::class,
			'label' => Craft::t('app', 'Edit'),
		]);

		// Delete
		$actions[] = Craft::$app->getElements()->createAction([
			'type' => Delete::class,
			'confirmationMessage' => Craft::t('app', 'Are you sure you want to delete this element?'),
			'successMessage' => Craft::t('app', 'Element deleted.'),
		]);

		return $actions;
	}

	/**
	 * @inheritdoc
	 */
	protected function crumbs(): array
	{
		$crumbs = [
			[
				'label' => Craft::t('websitedocumentation', 'Documentation'),
				'url' => 'website-documentation',
			],
			[
				'label' => Craft::t('websitedocumentation', 'CMS Guide Entries'),
				'url' => 'website-documentation/guides',
			],
		];

		return $crumbs;
	}
}

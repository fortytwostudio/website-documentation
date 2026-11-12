<?php
namespace fortytwostudio\websitedocumentation\services;

use Craft;
use craft\db\Query;
use	craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Site;
use craft\models\Structure;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;
use fortytwostudio\websitedocumentation\models\Navigation as NavigationModel;
use fortytwostudio\websitedocumentation\elements\NavElement;
use fortytwostudio\websitedocumentation\records\Navigation as NavigationRecord;

use yii\base\Component;
use yii\db\ActiveRecord;

class Menus extends Component
{
	// Constants
	// =========================================================================

	public const EVENT_BEFORE_SAVE_NAV = 'beforeSaveNav';
	public const EVENT_AFTER_SAVE_NAV = 'afterSaveNav';

	// Properties
	// =========================================================================

	// Public Methods
	// =========================================================================

	/*
	 * @title: Create Default Menus
	 * @desc: Create default menus for each editable site
	 */

	public function createDefault(?int $siteId = null)
	{
		// Get the sites
		if ($siteId == null) {
			$sites = Craft::$app->getSites();
			$editableSites = $sites->getAllSiteIds();
		} else {
			$editableSites = [$siteId];
		}

		// Menu Name's array
		$menus = [
			"Style Guide",
		];

		// Loop through sites and add Menus
		foreach ($editableSites as $siteId)
		{
			// Check a menu doesn't already exist
			$menusExist = !empty($this->getMenusBySiteId($siteId));

			// If no menus exist, we can add some
			if (!$menusExist)
			{
				// Loop through each menu
				foreach ($menus as $element)
				{
					$menu = new NavigationModel();
					$menu->name = $element;
					$menu->handle = StringHelper::toHandle($element);
					$menu->siteId = $siteId;

					$tab1 = new FieldLayoutTab(['name' => 'Element']);
					$tab1->setLayout($menu->fieldLayout);

					$menu->fieldLayout->setTabs([$tab1]);

					if (!$this->saveMenu($menu)) {
						$this->setFailFlash(Craft::t('websitedocumentation', 'Unable to save Menu.'));

						Craft::$app->getUrlManager()->setRouteParams([
							'menu' => $menu,
						]);

						return null;
					}
				}
			}
		}

	}

	/*
	 * @title: Save Menus
	 * @desc: Save the menus to the Database
	 */
	public function saveMenu(NavigationModel $menu): bool
	{
		$isNew = !$menu->id;

		if ($isNew) {
			$menu->uid = StringHelper::UUID();

			$menu->sortOrder = (new Query())
				->from(['{{%documentation_navigations}}'])
				->max('[[sortOrder]]') + 1;

		}

		$structureId = $menu->structureId;
		$navRecord = $this->_getNavRecord($menu->uid);
		$navRecord->name = $menu->name;
		$navRecord->handle = $menu->handle;
		$navRecord->siteId = $menu->siteId;
		$navRecord->sortOrder = $menu->sortOrder;
		$navRecord->defaultPlacement = NavigationModel::DEFAULT_PLACEMENT_END;
		$navRecord->uid = $menu->uid;

		// Structure
		$structuresService = Craft::$app->getStructures();
		$structure = $structureId ? $structuresService->getStructureById($structureId, true) : new Structure(['id' => $structureId]);
		$structuresService->saveStructure($structure);

		$navRecord->structureId = $structure->id;

		if (!empty($menu->fieldLayout)) {
			// Save the field layout
			$layout = FieldLayout::createFromConfig(reset($menu->fieldLayout));
			$layout->id = $navRecord->fieldLayoutId;
			$layout->type = NavElement::class;
			$layout->uid = key($menu->fieldLayout);

			Craft::$app->getFields()->saveLayout($layout, false);

			$navRecord->fieldLayoutId = $layout->id;
		} else if ($navRecord->fieldLayoutId) {
			// Delete the field layout
			Craft::$app->getFields()->deleteLayoutById($navRecord->fieldLayoutId);

			$navRecord->fieldLayoutId = null;
		}

		$navRecord->save(false);

		return true;

	}

	/*
	 * @title: Get All Menus
	 * @desc: Get all the menus
	 */
	public function getMenus() : array
	{
		$query = (new Query())
			->select([
				'menus.id',
				'menus.structureId',
				'menus.fieldLayoutId',
				'menus.name',
				'menus.handle',
				'menus.sortOrder',
				'menus.siteId',
				'menus.defaultPlacement',
				'menus.uid',
			])
			->from(['menus' => '{{%documentation_navigations}}'])
			->where([
				'menus.dateDeleted' => null,
			])
			->orderBy(['sortOrder' => SORT_ASC])
			->all();

		$menus = [];
		foreach ($query as $result) {
			$menus[] = new NavigationModel($result);
		}

		return $menus;
	}

	/*
	 * @title: Get Site Menus
	 * @desc: Get all the menus for a specific site
	 * @params:
		* $site: Craft Site Object
	 */
	public function getSiteMenus(Site $site) : array
	{
		$query = (new Query())
			->select([
				'menus.id',
				'menus.structureId',
				'menus.fieldLayoutId',
				'menus.name',
				'menus.handle',
				'menus.sortOrder',
				'menus.siteId',
				'menus.defaultPlacement',
				'menus.uid',
			])
			->from(['menus' => '{{%documentation_navigations}}'])
			->where([
				'menus.dateDeleted' => null,
				'menus.siteId' => $site->id,
			])
			->orderBy(['sortOrder' => SORT_ASC])
			->all();

		foreach ($query as $result) {
			$menus[] = new NavigationModel($result);
		}

		return $query;
	}

	/*
	 * @title: Get Menu by ID
	 * @desc: Get a menu using it's ID as an identifier
	 * @params:
		* $site: Craft Site Object
	 */
	public function getMenuById(int $id): NavigationModel
	{
		$query = (new Query())
			->select([
				'menus.id',
				'menus.structureId',
				'menus.fieldLayoutId',
				'menus.name',
				'menus.handle',
				'menus.sortOrder',
				'menus.siteId',
				'menus.defaultPlacement',
				'menus.uid',
			])
			->from(['menus' => '{{%documentation_navigations}}'])
			->where([
				'menus.id' => $id,
			])
			->orderBy(['sortOrder' => SORT_ASC])
			->one();

		$menu = new NavigationModel($query);

		return $menu;
	}

	/*
	 * @title: Get Menus by Site ID
	 * @desc: Get all menus using their Site ID as an identifier
	 * @params:
		* $site: Craft Site Object
	 */
	public function getMenusBySiteId(int $id): array
	{
		$query = (new Query())
			->select([
				'menus.id',
				'menus.structureId',
				'menus.fieldLayoutId',
				'menus.name',
				'menus.handle',
				'menus.sortOrder',
				'menus.siteId',
				'menus.defaultPlacement',
				'menus.uid',
			])
			->from(['menus' => '{{%documentation_navigations}}'])
			->where([
				'menus.siteId' => $id,
			])
			->orderBy(['sortOrder' => SORT_ASC])
			->all();

		$menus = [];
		foreach ($query as $result) {
			$menus[] = new NavigationModel($result);
		}

		return $menus;
	}

	/*
	 * @title: Get Menu by UID
	 * @desc: Get a menu using it's UID as an identifier
	 * @params:
		* $site: Craft Site Object
	 */
	public function getMenuByUid(string $uid): NavigationModel
	{
		$query = (new Query())
			->select([
				'menus.id',
				'menus.structureId',
				'menus.fieldLayoutId',
				'menus.name',
				'menus.handle',
				'menus.sortOrder',
				'menus.defaultPlacement',
				'menus.uid',
			])
			->from(['menus' => '{{%documentation_navigations}}'])
			->where([
				'menus.uid' => $uid,
			])
			->orderBy(['sortOrder' => SORT_ASC])
			->one();

		$menu = new NavigationModel($query);

		return $menu;
	}

	/*
	 * @title: Get menu elements
	 * @desc: Get all the elements used within the menu
	 */
	public function getMenuElements(int $menuId, $siteId = null): array
	{
		$elements = NavElement::find()
			->menuId($menuId)
			->status(null)
			->siteId($siteId)
			->all();

		return $elements;
	}

	/*
	 * @title: Get Parent Options
	 * @desc: Get all the elements used within the menu
	 */
	public function getParentOptions($elements, $menu): array
	{
		$parentOptions[] = [
			'label' => '',
			'value' => 0,
		];

		foreach ($elements as $element) {
			$label = '';

			for ($i = 1; $i < $element->level; $i++) {
				$label .= '    ';
			}

			$label .= $element->title;

			$parentOptions[] = [
				'label' => $label,
				'value' => $element->id,
			];
		}

		return $parentOptions;
	}

	/*
	 * @title: Get Element Tabs
	 * @desc: Get all the element types to use within a menu
	 */
	public function getElementTabs($menu): array
	{
		$tabs = [];

		$registeredElementTypes = WebsiteDocumentation::getInstance()->elementTypes->getRegisteredElementTypes();

		foreach ($registeredElementTypes as $elementtype) {
			$enabled = $nav->permissions[get_class($elementtype)]['enabled'] ?? true;

			if ($enabled) {
				$key = StringHelper::toKebabCase($elementtype->displayName());

				$tabs[$key] = [
					'label' => $elementtype->displayName(),
					'button' => Craft::t('websitedocumentation', 'Add {name}', ['name' => $elementtype->displayName()]),
					'type' => get_class($elementtype),
					'category' => 'elementType',
					'nodeType' => $elementtype,
				];
			}
		}

		return $tabs;
	}

	/*
	 * @title: Get Element By Id
	 * @desc: Get the Navigation element by it's ID
	 */
	public function getElementById(int $id, mixed $siteId = null, array $criteria = []): ?NavElement
	{
		return Craft::$app->getElements()->getElementById($id, NavElement::class, $siteId, $criteria);
	}

	// Private Methods
	// =========================================================================

	private function _getNavRecord(string $uid): ActiveRecord|array
	{
		$query = NavigationRecord::find();
		$query->andWhere(['uid' => $uid]);
		return $query->one() ?? new NavigationRecord();
	}

}

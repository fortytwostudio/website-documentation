<?php
namespace fortytwostudio\websitedocumentation\elements;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;
use fortytwostudio\websitedocumentation\elements\db\NavElementQuery;
use fortytwostudio\websitedocumentation\events\ElementActiveEvent;
use fortytwostudio\websitedocumentation\elementtypes\StyleGuideType;
use fortytwostudio\websitedocumentation\elementtypes\CustomUrl;
use fortytwostudio\websitedocumentation\models\Navigation;

use fortytwostudio\websitedocumentation\records\NavigationElement as ElementRecord;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\controllers\ElementIndexesController;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\elements\actions\Delete;
use craft\elements\actions\Duplicate;
use craft\elements\actions\Edit;
use craft\elements\actions\Restore;
use craft\elements\actions\SetStatus;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQuery;
use craft\errors\UnsupportedSiteException;
use craft\events\MoveElementEvent;
use craft\fields\data\ColorData;
use craft\helpers\App;
use Craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\Site;
use craft\services\Structures;

use Throwable;

use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\BaseHtml;
use yii\validators\Validator;

use Twig\Markup;

class NavElement extends Element
{
    // Constants
    // =========================================================================

    public const EVENT_ELEMENT_ACTIVE = 'modifyElementActive';


    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('websitedocumentation', 'Element');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('websitedocumentation', 'Elements');
    }

    public static function refHandle(): ?string
    {
        return 'navElement';
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function find(): NavElementQuery
    {
        return new NavElementQuery(static::class);
    }

    protected static function defineSources(string $context): array
    {
        $sources = [];

        $menus = WebsiteDocumentation::getInstance()->guideMenus->getMenus();

        foreach ($menus as $menu) {

            $sources[] = [
                'key' => 'menu:' . $menu["uid"],
                'label' => Craft::t('site', $menu["name"]),
                'data' => ['handle' => $menu["handle"]],
                'criteria' => ['menuId' => $menu["id"]],
				'structureId' => $menu["structureId"],
				'structureEditable' => true,
            ];
        }

        return $sources;
    }

    protected static function defineSortOptions(): array
    {
        // We must override the sort options, otherwise any in `defineTableAttributes` will be added.
        // We really only want a structure sort option, and disallow users from changing, but we run
        // into issues when viewing trashed elements, which have no structure. Thus, we need at least another option.
        return [
            'id' => Craft::t('app', 'ID'),
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'typeLabel' => ['label' => Craft::t('app', 'Type')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        // These are static and cannot be customised by users
        return [
            'typeLabel',
        ];
    }

    protected static function defineActions(string $source): array
    {
        // Get the selected site
        $controller = Craft::$app->controller;

        if ($controller instanceof ElementIndexesController) {
            /** @var ElementQuery $elementQuery */
            $elementQuery = $controller->getElementQuery();
        } else {
            $elementQuery = null;
        }


        // Get the group we need to check permissions on
        if (preg_match('/^menu:(\d+)$/', $source, $matches)) {
            $menu =  WebsiteDocumentation::getInstance()->guideMenus->getMenuByUid($matches[1]);
        } else if (preg_match('/^menu:(.+)$/', $source, $matches)) {
            $menu = WebsiteDocumentation::getInstance()->guideMenus->getMenuByUid($matches[1]);
        }

        // Now figure out what we can do with it
        $actions = [];
        $elementsService = Craft::$app->getElements();

        if ($menu !== null) {
            // Set Status
            $actions[] = SetStatus::class;

            // Edit
            $actions[] = $elementsService->createAction([
                'type' => Edit::class,
                'label' => Craft::t('app', 'Edit Element'),
            ]);

            // Duplicate
            $actions[] = Duplicate::class;

            $actions[] = [
				'type' => Duplicate::class,
				'deep' => true,
			];

            // Delete
            $actions[] = Delete::class;

            $actions[] = [
				'type' => Delete::class,
				'withDescendants' => true,
			];
        }

        // Restore
        $actions[] = $elementsService->createAction([
            'type' => Restore::class,
            'successMessage' => Craft::t('app', 'Elements restored.'),
            'partialSuccessMessage' => Craft::t('app', 'Some elements restored.'),
            'failMessage' => Craft::t('app', 'Elements not restored.'),
        ]);

        return $actions;
    }


    // Properties
    // =========================================================================

    public ?int $id = null;
    public ?int $elementId = null;
    public ?int $siteId = null;
    public ?int $menuId = null;
    public ?string $type = null;

    public ?string $uri = null;
    public ?bool $deletedWithNav = false;

    private ?string $_url = null;
    private ?ElementInterface $_element = null;
    private array $_elementTypes = [];
    private ?string $_elementUrl = null;
    private ?bool $_isActive = null;


    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();
    }

    public function createAnother(): ?self
    {
        $menu = $this->getNav();

        $element = Craft::createObject([
            'class' => self::class,
            'menuId' => $this->menuId,
            'siteId' => $this->siteId,
        ]);

        $element->enabled = $this->enabled;
        $element->setEnabledForSite($this->getEnabledForSite());

		// Structure parent
		$element->setParentId($this->getParentId());

        return $element;
    }

    public function canView(User $user): bool
    {
        return true;
    }

    public function canSave(User $user): bool
    {
        return true;
    }

    public function canDuplicate(User $user): bool
    {
        return true;
    }

    public function canDelete(User $user): bool
    {
        return true;
    }

    public function canCreateDrafts(User $user): bool
    {
        return true;
    }

	public function getChipLabelHtml(): string
	{
		// Detect if this is the element index
		$isElementIndex = Craft::$app->getRequest()->getParam('viewState.mode') === 'table';

		// When reloading nodes, get the modified HTML
		if (Craft::$app->getRequest()->getSegments() === ['actions', 'element-indexes', 'element-table-html']) {
			$isElementIndex = true;
		}

		// Only show this when editing the nav, in case these elements are listed by third parties
		if (!$isElementIndex) {
			return parent::getChipLabelHtml();
		}

		$title = $this->hasOverriddenTitle();

		$html = implode(' ', array_filter([
			$title ? Html::tag('span', '', ['class' => 'element-custom-title edit icon']) : false,
		]));

		return parent::getChipLabelHtml() . ($html ? Html::tag('span', $html, ['class' => 'element-info-icons']) : '') . Html::tag('a', Craft::t('websitedocumentation', 'Edit'), ['class' => 'btn small icon edit element-edit-btn']);
	}

    public function getElement(): ?ElementInterface
    {
        if ($this->_element !== null) {
            return $this->_element;
        }

        if (!$this->elementId) {
            return null;
        }

        return $this->_element = Craft::$app->getElements()->getElementById($this->elementId, $this->type, $this->getElementSiteId());
    }

    public function setElement($element = null): void
    {
        $this->_element = $element;
    }

    public function getElementSiteId(): ?int
    {
        if ($this->slug) {
            return (int)$this->slug;
        }

        return Craft::$app->getSites()->getCurrentSite()->id;
    }

    public function setElementSiteId($value): void
    {
        $this->slug = $value;
    }

    public function getElementSlug(): ?string
    {
        if ($element = $this->getElement()) {
            return $element->slug;
        }

        return '';
    }

	public function getCurrent(): bool
	{
		return $this->_getActive(false);
	}

	public function getActive($includeChildren = true): ?bool
	{
		$isActive = $this->_getActive($includeChildren);

		// Allow plugins to modify this value
		$event = new ElementActiveEvent([
			'element' => $this,
			'isActive' => $isActive,
		]);

		Event::trigger(static::class, self::EVENT_ELEMENT_ACTIVE, $event);

		return $event->isActive;
	}

	public function setIsActive($value): void
	{
		$this->_isActive = $value;
	}

	public function hasActiveChild(): ?bool
	{
		if ($this->hasDescendants) {
			$descendants = $this->descendants->all();

			foreach ($descendants as $descendant) {
				if ($descendant->getActive()) {
					$this->setIsActive(true);

					return $this->getActive();
				}
			}
		}

		return null;
	}

    public function getRawUrl(): ?string
    {
        return $this->_url;
    }

    public function getUrl($includeSuffix = true): ?string
    {
        if ($this->elementType()) {
            $url = $this->elementType()->getUrl();
        } else {
            $url = $this->getRawUrl();
        }

        return $url;
    }

    public function setUrl($value): void
    {
        $this->_url = $value;
    }

    public function getElementUrl()
    {
        if ($this->_elementUrl !== null) {
            $path = ($this->_elementUrl === '__home__') ? '' : $this->_elementUrl;

            return UrlHelper::siteUrl($path, null, null, $this->getElementSiteId());
        }

        $element = $this->getElement();

        return $element->url ?? null;
    }

    public function setElementUrl($value): void
    {
        $this->_elementUrl = $value;
    }

    public function getElementsUri(): string
    {
        if ($url = $this->getUrl()) {
            return str_replace(UrlHelper::siteUrl('', null, null, $this->siteId), '', $url);
        }

        return '';
    }

    public function getLinkAttributes($extraAttributes = null): Markup
    {
        $object = $this->_getObject();

        $classes = $this->classes ? Craft::$app->getView()->renderObjectTemplate($this->classes, $object) : null;

        $attributes = [
            'href' => $this->getUrl(),
            'target' => $this->newWindow ? '_blank' : null,
            'rel' => $this->newWindow ? 'noopener' : null,
            'class' => $classes,
        ];

        foreach ($this->customAttributes as $attribute) {
            $key = $attribute['attribute'];
            $val = $attribute['value'];

            $attributes[$key] = Craft::$app->getView()->renderObjectTemplate($val, $object);
        }

        // Filter out any values
        $attributes = array_filter($attributes);

        if (is_array($extraAttributes)) {
            $attributes = array_merge_recursive($attributes, array_filter($extraAttributes));
        }

        return Template::raw(BaseHtml::renderTagAttributes($attributes));
    }

    public function getLink($attributes = null): ?Markup
    {
        return Template::raw('<a ' . $this->getLinkAttributes($attributes) . '>' . Html::encode($this->__toString()) . '</a>');
    }

    public function getTarget(): string
    {
        return $this->newWindow ? '_blank' : '';
    }

    public function getNav(): Navigation
    {
        if ($this->menuId === null) {
            throw new InvalidConfigException('Element is missing its navigation ID');
        }

        $nav = WebsiteDocumentation::getInstance()->guideMenus->getMenuById($this->menuId);

        if (!$nav) {
            throw new InvalidConfigException('Invalid navigation ID: ' . $this->menuId);
        }

        return $nav;
    }

    public function elementType()
    {
        // Check if we've cached the element type. by sure to check by key to prevent cache
        $_elementType = $this->_elementTypes[$this->type] ?? null;

        if ($_elementType != null) {
            // If a custom element type, be sure to send through this element
            $_elementType->element = $this;

            return $_elementType;
        }

        $registeredElementTypes = WebsiteDocumentation::getInstance()->elementTypes->getRegisteredElementTypes();

        foreach ($registeredElementTypes as $registeredelementType) {
            if ($this->type === $registeredelementType::class) {
                $registeredelementType->element = $this;

                return $this->_elementTypes[$this->type] = $registeredelementType;
            }
        }

        return null;
    }

    public function getTypeLabel()
    {
        try {
            if (class_exists($this->type)) {
                return $this->type::displayName();
            }
        } catch (Throwable $e) {
            // This will throw an error if the class exists, but the plugin disabled/uninstalled,
            // despite the check with `class_exists()`
        }

        $classNameParts = explode('\\', $this->type);

        return array_pop($classNameParts);
    }

    public function getTypeLabelHtml(): string
    {
        $classNameParts = explode('\\', $this->type);
        $className = array_pop($classNameParts);

        // Convert Hex to RGB
        $color = '--element-type-color: ' . $this->getTypeColor() . ';';

        $type = 'element-type-' . StringHelper::toKebabCase($className);
        $item = Html::tag('span', $this->getTypeLabel(), ['class' => $type, 'title' => $this->url]);

        return Html::tag('div', $item, ['class' => 'element-type', 'style' => $color]);
    }

    public function getTypeColor()
    {
        $color = '#888888';

        try {
            $color = $this->type::getColor();
        } catch (Throwable $e) {
            // This will throw an error if the class exists, but the plugin disabled/uninstalled,
            // despite the check with `class_exists()`
        }

        // Convert to rgb to play nice with opacity alterations
        $colorData = new ColorData($color);
        $color = "{$colorData->getRed()},{$colorData->getGreen()},{$colorData->getBlue()}";

        return $color;
    }

	// public function isElement(): bool
	// {
	// 	return (bool)$this->getRegisteredElement();
	// }

    public function isCustom(): bool
    {
        return $this->type === CustomUrl::class;
    }

    public function isTitleOnly(): bool
    {
        return $this->type === StyleGuideType::class;
    }

    public function hasOverriddenTitle(): bool
    {
        $element = $this->getElement();

        return $element && $element->title !== $this->title;
    }

    public function beforeSave(bool $isNew): bool
    {
        $nav = $this->getNav();

		// Set the structure ID for Element::attributes() and afterSave()
		$this->structureId = $nav->structureId;

        if (!$this->duplicateOf && $this->hasNewParent()) {
            if ($parentId = $this->getParentId()) {
                $parentElement = WebsiteDocumentation::getInstance()->guideMenus->getElementById($parentId, '*', [
                    'preferSites' => [$this->siteId],
                    'drafts' => null,
                    'draftOf' => false,
                ]);

                if (!$parentElement) {
                    throw new InvalidConfigException("Invalid parent element ID: $parentId");
                }
            } else {
                $parentElement = null;
            }

            $this->setParent($parentElement);
        }

        // If this is propagating, we want to fetch the information for that site's linked element
        if ($this->propagating && $this->elementId) {
            $localeElement = Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId);

            if ($localeElement) {
                $this->elementSiteId = $localeElement->siteId;

                // Only update the title if we haven't overridden it
                if (!$this->hasOverriddenTitle()) {
                    $this->title = $localeElement->title;
                }
            }
        }

        // If no title is set (for a custom element type for instance), generate one.
        if (!$this->title && $this->elementType()) {
            $this->title = $this->elementType()->getDefaultTitle();
        }

        // Save the linked element's site id to the slug - again, our hacky way...
        if ($this->getElementSiteId()) {
            $this->slug = $this->elementSiteId = $this->getElementSiteId();
        }

        $this->elementId = null;

        // Allow element types to hook into things
        if ($this->elementType()) {
            $this->elementType()->beforeSaveElement($isNew);
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $nav = $this->getNav();

            // Get the element record
            if (!$isNew) {
                $record = ElementRecord::findOne($this->id);

                if (!$record) {
                    throw new InvalidConfigException("Invalid element ID: $this->id");
                }
            } else {
                $record = new ElementRecord();
                $record->id = (int)$this->id;
            }

            $record->elementId = $this->elementId;
            $record->menuId = (int)$this->menuId;
            $record->url = $this->getRawUrl();
            $record->type = $this->type;
			$record->parentId = $this->parentId;

            // Capture the dirty attributes from the record
            $dirtyAttributes = array_keys($record->getDirtyAttributes());

            $record->save(false);

            if ($this->getIsCanonical()) {
                // Has the parent changed?
                if ($this->hasNewParent()) {
                    $this->_placeInStructure($isNew, $nav);
                }
            }

            $this->setDirtyAttributes($dirtyAttributes);
        }

        parent::afterSave($isNew);
    }

    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        // Update the element record
        $data = [
            'deletedWithNav' => $this->deletedWithNav,
            'parentId' => null,
        ];

        if ($this->structureId) {
            // Remember the parent ID, in case the element needs to be restored later
            $parentId = $this->getAncestors(1)
                ->status(null)
                ->select(['elements.id'])
                ->scalar();

            if ($parentId) {
                $data['parentId'] = $parentId;
            }
        }

        Db::update('{{%documentation_navigation_elements}}', $data, [
            'id' => $this->id,
        ], [], false);

        return true;
    }

    public function afterRestore(): void
    {
        $nav = $this->getNav();

        // Add the element back into its structure
        $parent = self::find()
            ->structureId($nav->structureId)
            ->innerJoin(['j' => '{{%documentation_navigation_elements}}'], '[[j.parentId]] = [[elements.id]]')
            ->andWhere(['j.id' => $this->id])
            ->one();

        if (!$parent) {
            Craft::$app->getStructures()->appendToRoot($nav->structureId, $this);
        } else {
            Craft::$app->getStructures()->append($nav->structureId, $this, $parent);
        }

        parent::afterRestore();
    }

	public function afterMoveInStructure(int $structureId): void
	{
		// Was the node moved within its group's structure?
		$nav = $this->getNav();

		if ($nav->structureId == $structureId) {
			Craft::$app->getElements()->updateElementSlugAndUri($this, true, true, true);

			// If this is the canonical node, update its drafts
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
					$structuresService->moveAfter($nav->structureId, $draft, $lastElement);
					$lastElement = $draft;
				}
			}
		}

		parent::afterMoveInStructure($structureId);
	}

    public function getFieldLayout(): ?FieldLayout
    {
        $nav = $this->menuId === null ? null : $this->getNav();

        return $nav ? $nav->getFieldLayout() : null;
    }

    public function getCustomAttributesObject(): array
    {
        $object = [];

        foreach ($this->customAttributes as $attribute) {
            $object[$attribute['attribute']] = $attribute['value'];
        }

        return array_filter($object);
    }

    public function getLinkedElementId(): ?int
    {
        return $this->elementId;
    }

    public function setLinkedElementId($value): void
    {
        // This is a required proxy variable when editing a element, due to a conflicting `elementId`.
        if (is_array($value)) {
            $this->elementId = $value[0];
        } else {
            $this->elementId = (int)$value;
        }

        // Also check for `0` (string or int) and set correct value for type
        if (!$this->elementId) {
            $this->elementId = null;
        }
    }

    public function setLinkedElementSiteId($value): void
    {
        if ($value) {
            $this->elementSiteId = (int)$value;
        }
    }

	public function _getActive($includeChildren = true): bool
	{
		if ($this->_isActive && $includeChildren) {
			return true;
		}

		$request = Craft::$app->getRequest();
		$pageTrigger = Craft::$app->getConfig()->getGeneral()->getPageTrigger();

		// Don't run the for console requests. This is called when populating the Node element
		if ($request->getIsConsoleRequest()) {
			return false;
		}

		$siteUrl = trim(UrlHelper::siteUrl(), '/');
		$nodeUrl = (string)$this->getUrl(false);

		// If no URL and not a custom node, skip. Think passive nodes.
		if ($nodeUrl === '' && !$this->isCustom()) {
			return false;
		}

		// Get the full url to compare, this makes sure it works with any setup (either other domain per site or subdirs)
		// Using `getUrl()` would return the site-relative path, which isn't what we want to compare with.
		// Also trim the '/' and remove the query string to normalise for comparison.
		$currentUrl = trim(urldecode($request->absoluteUrl), '/');

		// Remove the query string from the URL - not needed to compare
		$currentUrl = preg_replace('/\?.*/', '', $currentUrl);

		// Compare things in lowercase, just in case
		$currentUrl = strtolower($currentUrl);
		$nodeUrl = strtolower($nodeUrl);

		// Is this a paginated request? If non-query string pagination, then cleanup currentUrl
		if (!str_starts_with($pageTrigger, '?')) {
			// Match against the entire path string as opposed to just the last segment so that we can support
			// "/page/2"-style pagination URLs
			$pageTrigger = preg_quote($pageTrigger, '/');

			if (preg_match("/^(?:(.*)\/)?$pageTrigger(\d+)$/", $currentUrl, $match)) {
				$currentUrl = $match[1];
			}
		}

		// Convert a root-relative node's URL to its absolute equivalent. Note we're not using the site URL,
		// because the node's URL will likely already contain that.
		if (UrlHelper::isRootRelativeUrl($nodeUrl)) {
			$nodeUrl = $request->hostInfo . '/' . trim($nodeUrl, '/');
		}

		// A final check if the node is still not an absolute URL, make it (a site) one.
		if (!UrlHelper::isAbsoluteUrl($nodeUrl)) {
			$nodeUrl = UrlHelper::siteUrl($nodeUrl);
		}

		// Trim the node's url to normalise for comparison, after we've resolved it to an absolute URL.
		$nodeUrl = trim($nodeUrl, '/');

		// Stop straight away if this is the homepage entry
		if ($this->_elementUrl === '__home__') {
			return $currentUrl === $nodeUrl;
		}

		// Check if they match, easy enough!
		$isActive = $currentUrl === $nodeUrl;

		// Also check if any children are active
		if ($includeChildren) {
			// Then, provide a helper based purely on the URL structure.
			// /example-page and /example-page/nested-page should both be active, even if both aren't nodes.

			// Include trailing slashes to check if the parent has a child, otherwise we get partial matches
			// for things like /some-entry and /some-entry-title - both would incorrectly match
			if (str_starts_with($currentUrl, $nodeUrl . '/')) {
				// Make sure we're not on the homepage (unless this node is for the homepage)
				if ($nodeUrl !== $siteUrl) {
					$isActive = true;
				}
			}

			// If the URLs match exactly
			if ($currentUrl === $nodeUrl) {
				// Make sure we're not on the homepage (unless this node is for the homepage)
				if ($nodeUrl !== $siteUrl) {
					$isActive = true;
				}
			}
		}

		return $isActive;
	}

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Must be included to allow `setAttributes()` to work, and treat it as safe. This is so the element
        // slide-out can update the type for draft-changes.
        $rules[] = [['linkedElementId', 'linkedElementSiteId', 'url', 'type', 'parentId'], 'safe'];

		$rules[] = [
			'title',
			function($attribute, $params, Validator $validator): void {
				if ($this->title === "Title Only") {
					$validator->addError($this, 'title', Craft::t('navigation', 'Please add a custom title'));
				}
			},
			'skipOnEmpty' => false,
			'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE, self::SCENARIO_ESSENTIALS],
		];

		// $rules[] = [
		// 	'elementId',
		// 	function($attribute, $params, Validator $validator): void {
		// 		// Don't check if this is a draft, likely just switched to different node type
		// 		if (!$this->getIsDraft() && $this->isElement() && empty($this->elementId)) {
		// 			// Add to both attributes as the element slide-out uses `linkedElementId`
		// 			$validator->addError($this, 'elementId', Craft::t('navigation', 'Element ID is required.'));
		// 			$validator->addError($this, 'linkedElementId', Craft::t('navigation', 'Linked Element ID is required.'));
		// 		}
		// 	},
		// 	'skipOnEmpty' => false,
		// 	'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE, self::SCENARIO_ESSENTIALS],
		// ];

        return $rules;
    }

    protected function attributeHtml(string $attribute): string
    {
        if ($attribute == 'typeLabel') {
            return $this->getTypeLabelHtml();
        } else if ($attribute == 'actions') {
            $tags = Html::tag('a', null, ['class' => 'settings icon', 'title' => 'Settings']) . Html::tag('a', null, ['class' => 'delete icon', 'title' => 'Delete']);

            return Html::tag('div', $tags);
        }

        return parent::attributeHtml($attribute);
    }

	protected function metaFieldsHtml(bool $static): string
	{
		$fields = [];

		// Type
		$fields[] = (function() use ($static) {
			$elementTypeOptions = [];
			$elementTabs = WebsiteDocumentation::getInstance()->guideMenus->getElementTabs($this->getNav());

			foreach ($elementTabs as $tab) {
				$elementTypeOptions[] = [
					'label' => Craft::t('site', $tab['label']),
					'value' => $tab['type'],
				];
			}

			$view = Craft::$app->getView();
			$typeInputId = $view->namespaceInputId('type');
			$js = <<<EOD
(() => {
const \$typeInput = $('#$typeInputId');
const editor = \$typeInput.closest('form').data('elementEditor');
if (editor) {
	editor.checkForm();
}
})();
EOD;
			$view->registerJs($js);

			return Cp::selectFieldHtml([
				'label' => Craft::t('websitedocumentation', 'Type'),
				'id' => 'type',
				'name' => 'type',
				'value' => $this->type,
				'options' => $elementTypeOptions,
			]);
		})();

		$fields[] = (function() use ($static) {
			if ($parentId = $this->getParentId()) {
				$parent = WebsiteDocumentation::getInstance()->guideMenus->getElementById($parentId, $this->siteId);
			} else {
				// If the element already has structure data, use it. Otherwise, use its canonical element
				/** @var self|null $parent */
				$parent = self::find()
					->siteId($this->siteId)
					->ancestorOf($this->lft ? $this : ($this->getIsCanonical() ? $this->id : $this->getCanonical(true)))
					->ancestorDist(1)
					->drafts(null)
					->draftOf(false)
					->status(null)
					->one();
			}

			$menu = $this->getNav();

			return Cp::elementSelectFieldHtml([
				'label' => Craft::t('app', 'Parent'),
				'id' => 'parentId',
				'name' => 'parentId',
				'elementType' => self::class,
				'selectionLabel' => Craft::t('app', 'Choose'),
				'sources' => ["menu:$menu->uid"],
				'criteria' => $this->_parentOptionCriteria($menu),
				'limit' => 1,
				'elements' => $parent ? [$parent] : [],
				'disabled' => $static,
			]);
		})();

		$fields[] = parent::metaFieldsHtml($static);

		return implode("\n", $fields);
	}


    // Private Methods
    // =========================================================================

    private function _getObject(): array
    {
        return [
            'currentUser' => Craft::$app->getUser()->getIdentity(),
        ];
    }

    private function _parentOptionCriteria(Navigation $nav): array
    {
        $parentOptionCriteria = [
            'siteId' => $this->siteId,
            'menuId' => $nav->id,
            'status' => null,
            'drafts' => null,
            'draftOf' => false,
        ];

        // Prevent the current element, or any of its descendants, from being selected as a parent
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

    private function _placeInStructure(bool $isNew, Navigation $nav): void
    {
        $parentId = $this->getParentId();
        $structuresService = Craft::$app->getStructures();

        // If this is a provisional draft and its new parent matches the canonical element’s, just drop it from the structure
        if ($this->isProvisionalDraft) {
            $canonicalParentId = self::find()
                ->select(['elements.id'])
                ->ancestorOf($this->getCanonicalId())
                ->ancestorDist(1)
                ->status(null)
                ->scalar();

            if ($parentId == $canonicalParentId) {
                $structuresService->remove($this->structureId, $this);
                return;
            }
        }

        $mode = $isNew ? Structures::MODE_INSERT : Structures::MODE_AUTO;

        if (!$parentId) {
            if ($nav->defaultPlacement === Navigation::DEFAULT_PLACEMENT_BEGINNING) {
                $structuresService->prependToRoot($this->structureId, $this, $mode);
            } else {
                $structuresService->appendToRoot($this->structureId, $this, $mode);
            }
        } else {
            if ($nav->defaultPlacement === Navigation::DEFAULT_PLACEMENT_BEGINNING) {
                $structuresService->prepend($this->structureId, $this, $this->getParent(), $mode);
            } else {
                $structuresService->append($this->structureId, $this, $this->getParent(), $mode);
            }
        }
    }
}

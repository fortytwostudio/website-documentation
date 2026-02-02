<?php
namespace fortytwostudio\websitedocumentation;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\services\Sites;
use craft\db\Table;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\SiteEvent;
use craft\events\PluginEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\fieldlayoutelements\TitleField;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\Structure;
use craft\web\Request;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;

use craft\services\Elements;
use craft\services\Dashboard;
use craft\events\ElementEvent;
use craft\elements\Entry;

use yii\base\Event;

use fortytwostudio\websitedocumentation\assetbundles\DocumentationAsset;
use fortytwostudio\websitedocumentation\assetbundles\GuideEditAsset;
use fortytwostudio\websitedocumentation\models\Settings;
use fortytwostudio\websitedocumentation\elements\NavElement;
use fortytwostudio\websitedocumentation\elements\GuideEntry;
use fortytwostudio\websitedocumentation\twigextensions\DocumentationTwigExtension;
use fortytwostudio\websitedocumentation\widgets\DocumentationWidget;
use fortytwostudio\websitedocumentation\variables\DocumentationVariable;

use fortytwostudio\websitedocumentation\services\CreateField;
use fortytwostudio\websitedocumentation\services\CreateEntryType;
use fortytwostudio\websitedocumentation\services\CreateEntries;
use fortytwostudio\websitedocumentation\services\CreateNavElements;
use fortytwostudio\websitedocumentation\services\ElementTypes;
use fortytwostudio\websitedocumentation\services\GuideService;
use fortytwostudio\websitedocumentation\services\Menus;
use fortytwostudio\websitedocumentation\services\ReturnSettings;

/* Logging */
use craft\log\MonologTarget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;

/**
 * @author    fortytwostudio
 * @package   WebsiteDocumentation
 * @since     1.0.0
 *
 */
class WebsiteDocumentation extends Plugin
{
	public static ?WebsiteDocumentation $plugin;
    public static ?DocumentationVariable $documentationVariable;

	public bool $hasCpSection = true;
	public bool $hasCpSettings = true;
	public static ?Settings $settings;

	// Public Methods
	// =========================================================================

	public function init()
	{
		parent::init();
		self::$plugin = $this;
		self::$settings = $this->getSettings();
		self::$documentationVariable = new DocumentationVariable();

		// Create Custom Alias
		Craft::setAlias('@websitedocumentation', __DIR__);

		// Register services
		$this->setComponents([
			'createField'     	=> CreateField::class,
			'createEntryType'   => CreateEntryType::class,
			'createEntries'     => CreateEntries::class,
			'createNavElements'	=> CreateNavElements::class,
			'elementTypes'		=> ElementTypes::class,
			'guideService'		=> GuideService::class,
			'guideMenus'     	=> Menus::class,
			'returnSettings'    => ReturnSettings::class,
		]);

		if (Craft::$app->getRequest()->getIsCpRequest()) {

			// Register Control Panel URL Rules
			$this->_registerRoutes();

			// Register Field Events
			$this->_registerFieldLayoutListeners();
		}

		// Register Widgets
		$this->_registerDocumentationWidgets();

		// Register Craft Variables
		$this->_registerCraftVariables();

		// Register Twig Extensions
		$this->_registerTwigExtensions();

		// Register Logger
		$this->_registerLogger();

		// Register Asset Bundles
		$this->_registerAssetBundles();

		// Functions to run after install
		$this->_afterInstall();

		// Functions to run after a site is added
		$this->_afterSiteAdded();

		Craft::info(
			Craft::t("websitedocumentation", "{name} plugin loaded", [
				"name" => $this->name,
			]),
			__METHOD__
		);
	}

	// Rename the Control Panel Item & Add Sub Menu
	public function getCpNavItem(): ?array
	{
		// Get the site info
		$handle = Craft::$app->sites->currentSite->handle ?? "default";
		$url = Craft::$app->sites->currentSite->baseUrl;

		// Get the documentation url
		$config = WebsiteDocumentation::customConfig();
		$docUrl = $this->getDocUrl($config, $handle);

		// Create main navigation item
		$item = [
			"label" => "Documentation",
			"url" =>  "website-documentation",
			"icon" => "@app/icons/book.svg",
		];

		// Get Documentation Settings
		$settings = $this->getSettings();

		// Check for Multi-Site
		$request = Craft::$app->getRequest();
        $siteHandle = '';
        if ($request->getSegment(1) === 'website-documentation') {
            $segments = $request->getSegments();
            $lastSegment = end($segments);
            $site = Craft::$app->getSites()->getSiteByHandle($lastSegment);
            if ($site !== null) {
                $siteHandle = '/' . $lastSegment;
            }
        }

		// Add Sub Navigation items
		$item = array_merge($item, [
			"subnav" => [
				"dashboard" => [
					"label" => "Dashboard",
					"url" => "website-documentation/dashboard" . $siteHandle,
				],
				"style-guide" => [
					"label" => "View Style Guide",
					"url" => $url . $docUrl . "/style-guide",
					"external" => true,
				],
				"cms-guide" => [
					"label" => "View CMS Guide",
					"url" => $url . $docUrl . "/cms-guide",
					"external" => true,
				],
			],
		]);

		// Check if Menus are allowed to shown on this environment
		$displayMenus = isset($config["displayMenus"]) ? $config["displayMenus"] : true;
		if ($displayMenus) {
			$item["subnav"]["guide-menus"] = [
				"label" => "Guide Menus",
				"url" => "website-documentation/menus" . $siteHandle,
			];
		}

		// Check if Entries are allowed to be shown on this environment
		$displayEntries = isset($config["displayEntries"]) ? $config["displayEntries"] : true;
		if ($displayEntries) {
			$item["subnav"]["guide-entries"] = [
				"label" => "CMS Guide Entries",
				"url" => "website-documentation/guides" . $siteHandle,
			];
		}

		// If changes are allowed, we can show the settings. These will be saved in the project config
		$editableSettings = true;
		$general = Craft::$app->getConfig()->getGeneral();
		if (!$general->allowAdminChanges) {
			$editableSettings = false;
		}

		if ($editableSettings) {
			$item["subnav"]["settings"] = [
				"label" => "Settings",
				"url" => "website-documentation/settings" . $siteHandle,
			];
		}

		return $item;
	}

	public static function customConfig()
	{
		$config = Craft::$app->config->getConfigFromFile(self::$plugin->id);

		if ($config) {
			return $config;
		}

	}

	// Rename the Control Panel Item & Add Sub Menu
	public static function getDocUrl($config, $handle): ?string
	{
		if (empty($config))
		{
			$docUrl = 'website-documentation';
		} else {
			if (isset($config['documentationUrl']) || isset($config['url'])) {
				$docUrl = isset($config['documentationUrl']) ? $config['documentationUrl'] : $config['url'] ;
			}  elseif(isset($config[$handle]['documentationUrl']) || isset($config[$handle]['url'])) {
				$docUrl = isset($config[$handle]['documentationUrl']) ? $config[$handle]['documentationUrl'] : $config[$handle]['url'];
			} else {
				$docUrl = 'website-documentation';
			}
		}

		return $docUrl;

	}

	public function createConfig()
	{
		$structure = new Structure();

		Craft::$app->getStructures()->saveStructure($structure);

		// We need to fetch the UID
		$structure = Craft::$app->getStructures()->getStructureById($structure->id);

		$settings = $this->getSettings()->getAttributes();
		$settings['structureUid'] = $structure->uid;

		// Save!
		Craft::$app->getPlugins()->savePluginSettings($this, $settings);

		return $structure;
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @title: Settings Model
	 * @desc: Register the settings model for the plugin
	 */
	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	/**
	 * @title: Settings HTML
	 * @desc: Register the settings html for the plugin
	 */
	protected function settingsHtml(): string
	{
		return Craft::$app
			->getView()
			->renderTemplate("websitedocumentation/settings", [
				"settings" => $this->getSettings(),
			]);
	}

	// Private Methods
	// =========================================================================

	/**
	 * @title: Registers Routes
	 * @desc: Register custom routes for our plugins settings
	 */
	private function _registerRoutes()
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			function (RegisterUrlRulesEvent $event) {
				$routes = [
					'website-documentation/settings' => 'websitedocumentation/settings/plugin-settings', // Settings
					'website-documentation/settings/<settingPage>' => 'websitedocumentation/settings/plugin-settings', // Settings
					"website-documentation" => [
						"template" => "websitedocumentation/dashboard", // Dashboard
					],
					"website-documentation/dashboard" => [
						"template" => "websitedocumentation/dashboard", // Dashboard
					],
					"website-documentation/menus"
						=> 'websitedocumentation/menus/index', // This is going towards the MenuController
					"website-documentation/menus/edit/<menuId:\d+>"
						=> "websitedocumentation/menus/edit-menu", // This is going towards the MenuController
					"website-documentation/guides"
						=> 'websitedocumentation/guide/index',
					"website-documentation/guides/<elementId:\d+>"
						=> "elements/edit",
				];
				$event->rules = array_merge($event->rules, $routes);
			}
		);
	}

	/**
	 * @title: Registers Control Panel Url Rules
	 * @desc: Register custom rules for the Url's used by the plugin
	 */
	private function _registerFieldLayoutListeners()
	{
		Event::on(FieldLayout::class,
		FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
		function(DefineFieldLayoutFieldsEvent $event) {
			if ($event->sender->type === NavElement::class) {
				$event->fields[] = TitleField::class;
			}
		});
	}

	/**
	 * @title: Register Widget
	 * @desc: Register custom widget to display guides on the dashboard
	 */
	private function _registerDocumentationWidgets()
	{
		Event::on(
			Dashboard::class,
			Dashboard::EVENT_REGISTER_WIDGET_TYPES,
			function (RegisterComponentTypesEvent $event) {
				$event->types[] = DocumentationWidget::class;
			}
		);
	}

	/**
	 * @title: Register Craft Variables
	 * @desc: Register variables which can be used within front-end templates using `craft.` alias
	 */
	private function _registerCraftVariables()
	{
		Event::on(
			CraftVariable::class,
			CraftVariable::EVENT_INIT,
			function (Event $e) {
				/** @var CraftVariable $variable */
				$variable = $e->sender;

				// Attach a service:
				$variable->set(
					"documentSettings", ReturnSettings::class
				);
			}
		);
	}

	/**
	 * @title: Register Asset Bundles
	 * @desc: Register the asset bundles
	 */
	private function _registerAssetBundles()
	{
		// Check we're on the CP side
		$request = Craft::$app->getRequest();

		// Get the url we've chosen
		$localeHandle = Craft::$app->getSites()->getCurrentSite()->handle;
		$config = WebsiteDocumentation::customConfig();
		$uri = $this->getDocUrl($config, $localeHandle);

		if (
			!$request->isCpRequest &&
			!$request->getIsConsoleRequest() &&
			$request->getSegment(1) === $uri
		) {
			Craft::$app
				->getView()
				->registerAssetBundle(DocumentationAsset::class);
		}

		Event::on(
		View::class,
		View::EVENT_BEFORE_RENDER_TEMPLATE,
		function (Event $event) {
			$request = Craft::$app->getRequest();

			$elementType = $event->variables["elementType"] ?? null;

			if ($elementType === GuideEntry::class) {
				Craft::$app->getView()->registerAssetBundle(GuideEditAsset::class);
			}
		});
	}

	/**
	 * @title: After Install
	 * @desc: Create the default menus for all sites when installed and add field & entry type
	 */
	private function _afterInstall()
	{
		Event::on(
		Plugins::class,
		Plugins::EVENT_AFTER_INSTALL_PLUGIN,
		function(PluginEvent $event)
		{
			if ($event->plugin === $this) {

				// Save plugin settings straight away
				$sites = Craft::$app->sites;
				$sites = $sites->getEditableSites();
				$settings = [];

				foreach ($sites as $site)
				{
					$settings[$site->handle] = [
						"logo" => "",
						"brandBgColor" => "",
						"brandTextColor" => "",
						"accentBgColor" => "",
						"accentTextColor" => "",
						"displayStyleGuide" => "1",
						"displayCmsGuide" => "1",
					];
				}

				Craft::$app->getPlugins()->savePluginSettings($this, ['sites' => $settings]);

				// Create Menus
				$this->getInstance()->guideMenus->createDefault();

				// Create Default Nav Elements
				$this->getInstance()->createNavElements->createDefault();

				// Create Structure
				if (!$this->getSettings()->structure)
				{
					$structure = $this->createConfig();
				}

				// Add Field
				$this->getInstance()->createField->create();

				// Create Entry Type
				$this->getInstance()->createEntryType->create();

				// Create Entries
				$this->getInstance()->createEntries->create($structure->id);

				// Redirect to the settings
				$request = Craft::$app->getRequest();
				if ($request->isCpRequest) {
					Craft::$app->getResponse()->redirect(UrlHelper::cpUrl(
						'website-documentation/settings'
					))->send();
				}
			}
		});
	}

	/**
	 * @title: After Site is added
	 * @desc: After a site is added, we want to update some bits
	 */
	private function _afterSiteAdded()
	{
		Event::on(
			Sites::class,
			Sites::EVENT_AFTER_SAVE_SITE,
			function (SiteEvent $event) {

				// Get the site id
				$siteId = $event->site->id;

				// Create Menus
				$this->getInstance()->guideMenus->createDefault($event->site->id);

				// Create Default Nav Elements
				$this->getInstance()->createNavElements->createDefault($siteId);

				// Create default guide entries
				$this->getInstance()->createEntries->create($this->getSettings()->structure, $siteId);
			}
		);
	}

	/**
     * Registers Twig extensions.
     */
    private function _registerTwigExtensions()
    {
        Craft::$app->view->registerTwigExtension(new DocumentationTwigExtension());
    }

	/**
	 * Registers logger.
	 */
	private function _registerLogger(): void
	{
		Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
			"name" => "website-documentation",
			"categories" => ["website-documentation"],
			"level" => LogLevel::INFO,
			"logContext" => false,
			"allowLineBreaks" => false,
			"formatter" => new LineFormatter(
				format: "%datetime% %message%\n",
				dateFormat: "Y-m-d H:i:s",
				allowInlineLineBreaks: true
			),
		]);
	}

}

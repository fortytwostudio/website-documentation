<?php
namespace fortytwostudio\websitedocumentation\services;

use fortytwostudio\websitedocumentation\base\ElementTypeUi;
use fortytwostudio\websitedocumentation\events\RegisterElementTypeEvent;
use fortytwostudio\websitedocumentation\elementtypes\StyleGuideType;
use fortytwostudio\websitedocumentation\elementtypes\MatrixType;
use fortytwostudio\websitedocumentation\elementtypes\CustomUrl;
use fortytwostudio\websitedocumentation\elements\NavElement;

use Craft;
use craft\base\Component;
use craft\helpers\Component as ComponentHelper;

class ElementTypes extends Component
{
    // Constants
    // =========================================================================

    public const EVENT_REGISTER_NODE_TYPES = 'registerElementTypes';

	// Properties
	// =========================================================================

	private array $_tempElements = [];

    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        $this->getRegisteredElementTypes();
    }

    public function getRegisteredElementTypes(): array
    {
        $nodeTypes = [
            StyleGuideType::class,
			MatrixType::class,
        ];

        $event = new RegisterElementTypeEvent([
            'types' => $nodeTypes,
        ]);

        $this->trigger(self::EVENT_REGISTER_NODE_TYPES, $event);

        $nodeTypes = $event->types;

        // Always add custom node at the end
        $nodeTypes[] = CustomUrl::class;

        $types = [];

        foreach ($nodeTypes as $type) {
            $types[] = ComponentHelper::createComponent([
                'type' => $type,
            ], ElementTypeUi::class);
        }

        return $types;
    }

	public function getElementsForNav($menuId, $siteId = null, $includeTemp = false): array
	{
		$elements = NavElement::find()
			->menuId($menuId)
			->status(null)
			->siteId($siteId)
			->status(null)
			->all();

		if ($includeTemp) {
			$elements = array_merge($elements, $this->_tempElements);
		}

		return $elements;
	}

	public function getParentOptions($elements, $nav): array
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

	public function setTempElements(array $elements): void
	{
		$this->_tempElements = $elements;
	}
}

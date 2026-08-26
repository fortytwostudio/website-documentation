<?php
namespace fortytwostudio\websitedocumentation\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;
use fortytwostudio\websitedocumentation\elements\NavElement;

use craft\fields\Matrix;
use fortytwostudio\websitedocumentation\elementtypes\MatrixType;
use fortytwostudio\websitedocumentation\elementtypes\StyleGuideType;
use yii\web\BadRequestHttpException;

use yii\web\Response;

class ElementsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionAddElements(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

		$elementsPost = $this->request->getRequiredParam('elements');

		// Convert Matrix submissions into individual Style Guide elements.
		$elementsPost = $this->_expandMatrixElements($elementsPost);

		// _setElementFromPost() reads from the request body, so replace the
		// submitted elements with the expanded collection.
		$bodyParams = $this->request->getBodyParams();
		$bodyParams['elements'] = $elementsPost;
		$this->request->setBodyParams($bodyParams);

		if ($elementsPost) {
			foreach ($elementsPost as $key => $data)
			{
				$element = $this->_setElementFromPost("elements.{$key}.");

            	// Add this new element to the nav, to assist with validation
            	WebsiteDocumentation::getInstance()->elementTypes->setTempElements([$element]);

				if (!Craft::$app->getElements()->saveElement($element, true)) {
					return $this->asModelFailure($element, Craft::t('websitedocumentation', 'Couldn’t add Element.'), 'element');
				}

				// Create new file for this if the website-documentaion folder has been installed
				WebsiteDocumentation::$plugin->guideService->createStyleGuideFile($data);

			}
		}

		return $this->asSuccess(Craft::t('websitedocumentation', 'Element{plural} added.', [
			'plural' => count($elementsPost) > 1 ? 's' : ''
		]));
    }

    public function actionGetParentOptions(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $menuId = $this->request->getRequiredParam('menuId');
        $siteId = $this->request->getParam('siteId');

        $elements = WebsiteDocumentation::getInstance()->elementTypes->getElementsForNav($menuId, $siteId);

        $options = [];

        if ($elements) {
            $options = WebsiteDocumentation::getInstance()->elementTypes->getParentOptions($elements, $elements[0]->nav);
        }

        return $this->asJson(['options' => $options]);
    }


    // Private Methods
    // =========================================================================

	/**
	* Expand Matrix generators into individual Style Guide elements.
	*
	* @throws BadRequestHttpException
	*/
	private function _expandMatrixElements(array $elements): array
	{
		$expandedElements = [];

		foreach ($elements as $data) {
			// Leave ordinary navigation items unchanged.
			if (($data['type'] ?? null) !== MatrixType::class) {
				$expandedElements[] = $data;
				continue;
			}

			$matrixFieldId = (int) ($data['matrixFieldId'] ?? 0);

			if (!$matrixFieldId) {
				throw new BadRequestHttpException(
					Craft::t(
						'websitedocumentation',
						'Please select a Matrix field.'
					)
				);
			}

			$matrixField = Craft::$app
				->getFields()
				->getFieldById($matrixFieldId);

			if (!$matrixField instanceof Matrix) {
				throw new BadRequestHttpException(
					Craft::t(
						'websitedocumentation',
						'The selected field is not a Matrix field.'
					)
				);
			}

			$entryTypes = $matrixField->getEntryTypes();

			if (!$entryTypes) {
				throw new BadRequestHttpException(
					Craft::t(
						'websitedocumentation',
						'The selected Matrix field has no entry types.'
					)
				);
			}

			foreach ($entryTypes as $entryType) {
				$expandedElements[] = [
					'parentId' => (string) ($data['parentId'] ?? '0'),
					'title' => $entryType->name,
					'type' => StyleGuideType::class,
					'menuId' => (string) $data['menuId'],
					'siteId' => (string) $data['siteId'],
				];
			}
		}

		return $expandedElements;
	}

    private function _setElementFromPost($prefix = ''): NavElement
    {
        $navElement = new NavElement();
        $navElement->title = $this->request->getParam("{$prefix}title", $navElement->title);
        $navElement->enabled = (bool)$this->request->getParam("{$prefix}enabled", $navElement->enabled);

		$elementId = $this->request->getParam("{$prefix}elementId", $navElement->elementId);

        // Handle elementselect field
        if (is_array($elementId)) {
            $elementId = $elementId[0] ?? null;
        }

        $navElement->elementId = $elementId;
        $navElement->siteId = $this->request->getParam("{$prefix}siteId", $navElement->siteId);
        $navElement->menuId = $this->request->getParam("{$prefix}menuId", $navElement->menuId);
        $navElement->url = $this->request->getParam("{$prefix}url", $navElement->url);
        $navElement->type = $this->request->getParam("{$prefix}type", $navElement->type);

        $navElement->parentId = $this->request->getParam("{$prefix}parentId");

        // Set field values.
        $navElement->setFieldValuesFromRequest('fields');

        return $navElement;
    }

}

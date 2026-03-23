<?php
namespace fortytwostudio\websitedocumentation\services;

use yii\base\Component;

use Craft;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\FieldGroup;

use craft\ckeditor\Plugin as CkEditor;
use craft\ckeditor\Field as CkEditorField;

use Exception;

class CreateField extends Component
{
	public static function create(): bool
	{
		$fieldsService = Craft::$app->getFields();

		$toolbar = [
			'sourceEditing',
			'heading',
			'bold',
			'italic',
			'link',
			'|',
			'bulletedList',
			'numberedList',
			'|',
			'insertImage',
			'|',
			'undo',
			'redo',
		];

		$options = [
			'heading' => [
				'options' => [
					[
						'model' => 'paragraph',
						'title' => 'Paragraph',
						'class' => 'ck-heading_paragraph',
					],
					[
						'model' => 'heading2',
						'view' => 'h2',
						'title' => 'Heading 2',
						'class' => 'ck-heading_heading2',
					],
					[
						'model' => 'heading3',
						'view' => 'h3',
						'title' => 'Heading 3',
						'class' => 'ck-heading_heading3',
					],
				],
			],
		];

		$field = $fieldsService->getFieldByHandle('websiteDocumentationText');

		if (!$field) {
			$field = $fieldsService->createField([
				'type' => CkEditorField::class,
				'name' => 'Website Documentation Text',
				'handle' => 'websiteDocumentationText',
				'instructions' => '',
				'searchable' => false,
				'toolbar' => $toolbar,
				'options' => $options,
				'headingLevels' => [2, 3],
				'availableVolumes' => '*',
				'purifyHtml' => true,
			]);
		} else {
			$field->toolbar = $toolbar;
			$field->options = $options;
			$field->headingLevels = [2, 3];
			$field->availableVolumes = '*';
			$field->purifyHtml = true;
		}

		if (!$fieldsService->saveField($field)) {
			throw new Exception("Couldn't save field");
		}

		return true;
	}
}

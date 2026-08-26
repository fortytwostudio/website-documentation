<?php
namespace fortytwostudio\websitedocumentation\services;

use yii\base\Component;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;
use fortytwostudio\websitedocumentation\elements\NavElement;

use Craft;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\Structure;
use craft\web\View;

// Illuminate
use Illuminate\Support\Collection;

use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

class GuideService extends Component
{
	public function getGuideType() {

		$entryType = Collection::make(
			Craft::$app->getEntries()->getEntryTypeByHandle('websiteDocumentationContent')
		);

		$guideEntryType = [];

		if ($entryType) {
			$guideEntryType = [
				'handle' => $entryType["handle"],
				'id' => $entryType["id"],
				'name' => $entryType["name"],
				'uid' => $entryType["uid"],
			];
		}

		return [
			'entryTypes' => $guideEntryType,
		];

	}

	public function createStyleGuideFile($data) {

		$handle = Craft::$app->sites->currentSite->handle ?? "default";
		$config = WebsiteDocumentation::customConfig();
		$folderName = WebsiteDocumentation::getDocUrl($config, $handle);

		$frontendTemplatesPath = Craft::getAlias('@templates');
		$folderPath = $frontendTemplatesPath . '/' . $folderName;

		if (is_dir($folderPath)) {
			$styleGuidePath = $folderPath . "/style-guide/sections";

			// Get file name
			$fileName = StringHelper::toKebabCase($data["title"]);

			// Check if this file has a parent
			$parentId = $data["parentId"] ?? null;

			// Example File
			$exampleFile = $styleGuidePath . '/example.twig';

			// If the parent Id is 0, this is a top level page.
			if ($parentId === "0") {
				// File Path
				$filePath = $styleGuidePath . '/' . $fileName . '.twig';

				if (!file_exists($filePath) && file_exists($exampleFile)) {
					$content = file_get_contents($exampleFile);
					file_put_contents($filePath, $content);
				};
			} else {
				$parentQuery = NavElement::find()
					->id($parentId)
					->one();

				if ($parentQuery)
				{
					$parentName = StringHelper::toKebabCase($parentQuery->title);

					// File Path
					$fileName = "$parentName.twig";
					$filePath = "$styleGuidePath/$fileName";

					// Folder Path
					$folderPath = "$styleGuidePath/$parentName";

					// Step One. Check if the parent is still a file and not a folder
					if (file_exists($filePath))
					{
						if (
							!is_dir($folderPath) &&
							!mkdir($folderPath, 0775, true) &&
							!is_dir($folderPath)
						) {
							throw new \RuntimeException(
								"Failed to create folder: {$folderPath}"
							);
						}
						if (is_file($filePath)) {
							unlink($filePath);
						}
					}

					// Step Two. Find the folder based on the file name
					$parentFolderPath = null;

					// Create an iteractor to look through all folders inside the style guide path.
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator($styleGuidePath, FilesystemIterator::SKIP_DOTS),
						RecursiveIteratorIterator::SELF_FIRST);

					// Set original option if the parent is a file and not yet a folder
					$parentIsFile = false;

					foreach ($iterator as $file)
					{
						if ($file->getFilename() === $parentName || $file->getFilename() === $fileName) {
							if ($file->isFile()) {
								$parentIsFile = true;
							}
							$parentFolderPath = $file->getPathname();
							break; // stop at first match
						}
					}

					// Step Three. If the parent is a file, we need to convert it to a folder
					if ($parentIsFile)
					{
						$folderPath = preg_replace('/\.twig$/', '', $parentFolderPath);
						if (
							!is_dir($folderPath) &&
							!mkdir($folderPath, 0775, true) &&
							!is_dir($folderPath)
						) {
							throw new \RuntimeException(
								"Failed to create folder: {$folderPath}"
							);
						}
						if (is_file($parentFolderPath)) {
							unlink($parentFolderPath);
						}

						$parentFolderPath = $folderPath;
					}

					// Step Four. Add new file inside Folder
					if ($parentFolderPath) {
						$fileName = StringHelper::toKebabCase($data["title"]);
						$filePath = "$parentFolderPath/$fileName.twig";

						if (!file_exists($filePath) && file_exists($exampleFile)) {
							$content = file_get_contents($exampleFile);
							file_put_contents($filePath, $content);
						};
					}

				}

			}

		}

	}

}

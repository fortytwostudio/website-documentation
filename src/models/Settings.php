<?php
namespace fortytwostudio\websitedocumentation\models;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;

use Craft;
use craft\base\Model;

class Settings extends Model
{
	// Public Properties
	// =========================================================================

	/**
	 * @var string
	 */

	public $name = "Website Documentation";
    public ?string $structureUid = null;
	public $sites;

	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public function rules(): array
	{
		return [
			[['structureUid'], 'string'],
		];
	}
}

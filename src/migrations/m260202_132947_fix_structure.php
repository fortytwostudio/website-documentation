<?php

namespace fortytwostudio\websitedocumentation\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;

use fortytwostudio\websitedocumentation\WebsiteDocumentation;

/**
 * m260202_132947_fix_structure migration.
 */
class m260202_132947_fix_structure extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
		$plugin = WebsiteDocumentation::getInstance();
		$handle = $plugin->id;

        $pc = Craft::$app->projectConfig;
        $settingsPath = "plugins.$handle.settings";
        $settings = $pc->get($settingsPath) ?? [];

        // If already set, don’t touch it
        if (!empty($settings['structureUid'])) {
            return true;
        }

        // 1) Find structureId(s) used by guide entries
        $structureIds = (new Query())
            ->select(['structureId'])
            ->from('{{%documentation_guide_entries}}')
            ->where(['not', ['structureId' => null]])
            ->distinct()
            ->column();

        if (empty($structureIds)) {
            throw new \RuntimeException('No structureId found in documentation_guide_entries.');
        }

        // If there are multiple, choose deterministically.
        // Most plugins will only ever have one.
        sort($structureIds);
        $structureId = (int)$structureIds[0];

        // 2) Convert ID -> UID
        $structure = Craft::$app->structures->getStructureById($structureId);
        if (!$structure?->uid) {
            throw new \RuntimeException("Structure $structureId not found.");
        }

        // 3) Save UID to Project Config
        $settings['structureUid'] = $structure->uid;

        $pc->set($settingsPath, $settings);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260202_132947_fix_structure cannot be reverted.\n";
        return false;
    }
}

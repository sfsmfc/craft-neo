<?php

namespace benf\neo\assets;

use benf\neo\elements\Block;
use benf\neo\events\SetConditionElementTypesEvent;
use benf\neo\Field;
use benf\neo\models\BlockType;
use benf\neo\models\BlockTypeGroup;
use benf\neo\Plugin as Neo;
use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Subscription;
use craft\commerce\elements\Variant;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use yii\base\Event;

/**
 * Class SettingsAsset
 *
 * @package benf\neo\assets
 * @author Spicy Web <plugins@spicyweb.com.au>
 * @author Benjamin Fleming
 * @since 3.0.0
 */
class SettingsAsset extends AssetBundle
{
    /**
     * @event SetConditionElementTypesEvent The event that's triggered when setting the element types for setting
     * conditions on when block types can be used
     *
     * ```php
     * use benf\neo\assets\SettingsAsset;
     * use benf\neo\events\SetConditionElementTypesEvent;
     * use yii\base\Event;
     *
     * Event::on(
     *     SettingsAsset::class,
     *     SettingsAsset::EVENT_SET_CONDITION_ELEMENT_TYPES,
     *     function (SetConditionElementTypesEvent $event) {
     *         $event->elementTypes[] = \some\added\ElementType::class;
     *     }
     * );
     * ```
     *
     * @since 3.2.0
     */
    public const EVENT_SET_CONDITION_ELEMENT_TYPES = 'setConditionElementTypes';

    /**
     * @var string[] Supported element types for setting conditions on when block types can be used
     */
    private static array $_conditionElementTypes = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@benf/neo/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = ['neo-configurator.css'];
        $this->js = [
            'neo-configurator.js',
            'neo-converter.js',
        ];

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function registerAssetFiles($view): void
    {
        $view->registerTranslations('neo', [
            'Actions',
            'Copy',
            'Paste',
            'Clone',
            'Delete',
            'Reorder',
            'Name',
            'What this block type will be called in the CP.',
            'Handle',
            'How you’ll refer to this block type in the templates.',
            'Description',
            'Enabled',
            'Whether this block type is allowed to be used.',
            'Max Blocks',
            'The maximum number of blocks of this type the field is allowed to have.',
            'All',
            'Child Blocks',
            'Which block types do you want to allow as children?',
            'Max Child Blocks',
            'The maximum number of child blocks this block type is allowed to have.',
            'Top Level',
            'Will this block type be allowed at the top level?',
            'Delete block type',
            'This can be left blank if you just want an unlabeled separator.',
            'Show',
            'Hide',
            'Use global setting (Show)',
            'Use global setting (Hide)',
            'Always Show Dropdown?',
            'Whether to show the dropdown for this group if it only has one available block type.',
            'Delete group',
            'Couldn’t copy block type.',
            'Couldn’t create new block type.',
        ]);

        parent::registerAssetFiles($view);
    }

    /**
     * Sets up the field layout designer for a given Neo field.
     *
     * @param Field $field The Neo field.
     * @return string
     */
    public static function createSettingsJs(Field $field): string
    {
        $event = new SetConditionElementTypesEvent([
            'elementTypes' => self::_getSupportedConditionElementTypes(),
        ]);
        Event::trigger(self::class, self::EVENT_SET_CONDITION_ELEMENT_TYPES, $event);
        self::$_conditionElementTypes = $event->elementTypes;

        $blockTypes = $field->getBlockTypes();
        $blockTypeGroups = $field->getGroups();
        [$blockTypeSettingsHtml, $blockTypeSettingsJs] = self::_renderBlockTypeSettings();
        $fieldLayoutHtml = Neo::$plugin->blockTypes->renderFieldLayoutDesigner(new FieldLayout(['type' => Block::class]));

        $jsSettings = [
            'namespace' => Craft::$app->getView()->getNamespace(),
            'blockTypes' => self::_getBlockTypesJsSettings($blockTypes),
            'groups' => self::_getBlockTypeGroupsJsSettings($blockTypeGroups),
            'blockTypeSettingsHtml' => $blockTypeSettingsHtml,
            'blockTypeSettingsJs' => $blockTypeSettingsJs,
            'fieldLayoutHtml' => $fieldLayoutHtml,
            'defaultAlwaysShowGroupDropdowns' => Neo::$plugin->settings->defaultAlwaysShowGroupDropdowns,
        ];

        $encodedJsSettings = Json::encode($jsSettings, JSON_UNESCAPED_UNICODE);

        return "Neo.createConfigurator($encodedJsSettings)";
    }

    /**
     * Returns the raw data from the given block types, in the format used by the settings generator JavaScript.
     *
     * @param BlockType[] $blockTypes
     * @return array
     */
    private static function _getBlockTypesJsSettings(array $blockTypes): array
    {
        $view = Craft::$app->getView();
        $jsBlockTypes = [];

        foreach ($blockTypes as $blockType) {
            [$blockTypeSettingsHtml, $blockTypeSettingsJs] = self::_renderBlockTypeSettings($blockType);
            $jsBlockTypes[] = [
                'id' => $blockType->id,
                'sortOrder' => $blockType->sortOrder,
                'name' => $blockType->name,
                'handle' => $blockType->handle,
                'enabled' => $blockType->enabled,
                'description' => $blockType->description,
                'minBlocks' => $blockType->minBlocks,
                'maxBlocks' => $blockType->maxBlocks,
                'minSiblingBlocks' => $blockType->minSiblingBlocks,
                'maxSiblingBlocks' => $blockType->maxSiblingBlocks,
                'minChildBlocks' => $blockType->minChildBlocks,
                'maxChildBlocks' => $blockType->maxChildBlocks,
                'childBlocks' => is_string($blockType->childBlocks) ? Json::decodeIfJson($blockType->childBlocks) : $blockType->childBlocks,
                'topLevel' => (bool)$blockType->topLevel,
                'errors' => $blockType->getErrors(),
                'settingsHtml' => $blockTypeSettingsHtml,
                'settingsJs' => $blockTypeSettingsJs,
                'fieldLayoutId' => $blockType->fieldLayoutId,
                'fieldLayoutConfig' => $blockType->getFieldLayout()->getConfig(),
                'groupId' => $blockType->groupId,
            ];
        }

        return $jsBlockTypes;
    }

    /**
     * Returns the raw data from the given block type groups, in the format used by the settings generator JavaScript.
     *
     * @param BlockTypeGroup[] $blockTypeGroups The Neo block type groups.
     * @return array
     */
    private static function _getBlockTypeGroupsJsSettings(array $blockTypeGroups): array
    {
        $jsBlockTypeGroups = [];

        foreach ($blockTypeGroups as $blockTypeGroup) {
            $jsBlockTypeGroups[] = [
                'id' => $blockTypeGroup->id,
                'sortOrder' => $blockTypeGroup->sortOrder,
                'name' => Craft::t('site', $blockTypeGroup->name),
                'alwaysShowDropdown' => $blockTypeGroup->alwaysShowDropdown,
            ];
        }

        return $jsBlockTypeGroups;
    }

    /**
     * @param BlockType|null $blockType
     * @return array
     */
    private static function _renderBlockTypeSettings(?BlockType $blockType = null): array
    {
        $view = Craft::$app->getView();
        $blockTypeId = $blockType?->id ?? '__NEOBLOCKTYPE_ID__';
        $oldNamespace = $view->getNamespace();
        $newNamespace = $oldNamespace . '[blockTypes][' . $blockTypeId . ']';
        $view->setNamespace($newNamespace);
        $view->startJsBuffer();

        $html = $view->namespaceInputs($view->renderTemplate('neo/block-type-settings', [
            'blockType' => $blockType,
            'conditions' => self::_getConditions($blockType),
        ]));

        $js = $view->clearJsBuffer();
        $view->setNamespace($oldNamespace);

        return [$html, $js];
    }

    /**
     * Gets the condition builder field HTML for a block type.
     *
     * @param BlockType|null $blockType
     * @return string[]
     */
    private static function _getConditions(?BlockType $blockType = null): array
    {
        $conditionsService = Craft::$app->getConditions();
        $conditionHtml = [];
        Neo::$isGeneratingConditionHtml = true;

        foreach (self::$_conditionElementTypes as $elementType) {
            $condition = !empty($blockType?->conditions) && isset($blockType->conditions[$elementType])
                ? $conditionsService->createCondition($blockType->conditions[$elementType])
                : $elementType::createCondition();
            $condition->mainTag = 'div';
            $condition->id = 'conditions-' . StringHelper::toKebabCase($elementType);
            $condition->name = "conditions[$elementType]";
            $condition->forProjectConfig = true;

            $conditionHtml[$elementType] = Cp::fieldHtml($condition->getBuilderHtml(), [
                'label' => Craft::t('neo', '{type} Condition', [
                    'type' => StringHelper::mb_ucwords($elementType::displayName()),
                ]),
                'instructions' => Craft::t('neo', 'Only allow this block type to be used on {type} if they match the following rules:', [
                    'type' => $elementType::pluralLowerDisplayName(),
                ]),
            ]);
        }

        Neo::$isGeneratingConditionHtml = false;

        return $conditionHtml;
    }

    /**
     * Get the element types supported by Neo for block type conditionals.
     *
     * @return string[]
     */
    private static function _getSupportedConditionElementTypes(): array
    {
        // In-built Craft element types
        $elementTypes = [
            Entry::class,
            Category::class,
            Asset::class,
            User::class,
            Tag::class,
            Address::class,
        ];

        // Craft Commerce element types
        if (Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            $elementTypes[] = Product::class;
            $elementTypes[] = Variant::class;
            $elementTypes[] = Order::class;
            $elementTypes[] = Subscription::class;
        }

        return $elementTypes;
    }
}

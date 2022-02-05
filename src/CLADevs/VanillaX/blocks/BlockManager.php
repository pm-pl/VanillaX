<?php

namespace CLADevs\VanillaX\blocks;

use CLADevs\VanillaX\blocks\block\button\NewWoodenButton;
use CLADevs\VanillaX\blocks\block\campfire\Campfire;
use CLADevs\VanillaX\blocks\block\CommandBlock;
use CLADevs\VanillaX\blocks\block\ComposerBlock;
use CLADevs\VanillaX\blocks\block\FlowerPotBlock;
use CLADevs\VanillaX\blocks\block\HopperBlock;
use CLADevs\VanillaX\blocks\block\log\NewLog;
use CLADevs\VanillaX\blocks\block\nylium\Nylium;
use CLADevs\VanillaX\blocks\block\slab\NewSlab;
use CLADevs\VanillaX\blocks\tile\campfire\RegularCampfireTile;
use CLADevs\VanillaX\blocks\tile\campfire\SoulCampfireTile;
use CLADevs\VanillaX\blocks\tile\FlowerPotTile;
use CLADevs\VanillaX\blocks\utils\CommandBlockType;
use CLADevs\VanillaX\configuration\features\BlockFeature;
use CLADevs\VanillaX\items\LegacyItemIds;
use CLADevs\VanillaX\items\ItemManager;
use CLADevs\VanillaX\utils\item\NonAutomaticCallItemTrait;
use CLADevs\VanillaX\utils\Utils;
use CLADevs\VanillaX\VanillaX;
use pocketmine\block\Block;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockIdentifierFlattened;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\BlockToolType;
use pocketmine\block\Door;
use pocketmine\block\Fence;
use pocketmine\block\FenceGate;
use pocketmine\block\Opaque;
use pocketmine\block\Planks;
use pocketmine\block\Stair;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\Transparent;
use pocketmine\block\utils\TreeType;
use pocketmine\block\WoodenPressurePlate;
use pocketmine\block\WoodenTrapdoor;
use pocketmine\data\bedrock\LegacyBlockIdToStringIdMap;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemIds;
use pocketmine\item\ToolTier;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class BlockManager{
    use SingletonTrait;

    public function __construct(){
        self::setInstance($this);
    }

    /**
     * @throws ReflectionException
     */
    public function startup(): void{
        $oldMap = LegacyBlockIdToStringIdMap::getInstance()->getStringToLegacyMap();

        foreach(Utils::getBlockIdsMap() as $name => $id){
            if(!isset($oldMap[$name])){
                BlockFeature::addLegacy(LegacyBlockIdToStringIdMap::getInstance(), $name, $id);
            }
        }

        $this->initializeRuntimeIds();
        $this->initializeBlocks();
        $this->initializeTiles();

        Server::getInstance()->getAsyncPool()->addWorkerStartHook(function(int $worker): void{
            Server::getInstance()->getAsyncPool()->submitTaskToWorker(new class() extends AsyncTask{

                public function onRun(): void{
                    BlockManager::getInstance()->initializeRuntimeIds();
                }
            }, $worker);
        });
    }

    public function initializeRuntimeIds(): void{
        $instance = RuntimeBlockMapping::getInstance();
        $method = new ReflectionMethod(RuntimeBlockMapping::class, "registerMapping");
        $method->setAccessible(true);

        $blockIdMap = Utils::getBlockIdsMap();
        $metaMap = [];

        foreach($instance->getBedrockKnownStates() as $runtimeId => $nbt){
            $mcpeName = $nbt->getString("name");
            $meta = isset($metaMap[$mcpeName]) ? ($metaMap[$mcpeName] + 1) : 0;
            $id = $blockIdMap[$mcpeName] ?? BlockLegacyIds::AIR;

            if($id !== BlockLegacyIds::AIR && $meta <= 15 && !BlockFactory::getInstance()->isRegistered($id, $meta)){
                $metaMap[$mcpeName] = $meta;
                $method->invoke($instance, $runtimeId, $id, $meta);
            }
        }
    }

    /**
     * @throws ReflectionException
     */
    private function initializeTiles(): void{
        $tileConst = [];
        foreach((new ReflectionClass(TileIds::class))->getConstants() as $id => $value){
            $tileConst[$value] = $id;
        }

        Utils::callDirectory("blocks/tile", function (string $namespace)use($tileConst): void{
            $rc = new ReflectionClass($namespace);

            if(!$rc->isAbstract()){
                if($rc->implementsInterface(NonAutomaticCallItemTrait::class)){
                    $diff = array_diff($rc->getInterfaceNames(), class_implements($rc->getParentClass()->getName()));

                    if(in_array(NonAutomaticCallItemTrait::class, $diff)){
                        return;
                    }
                }
                $tileID = $rc->getConstant("TILE_ID");
                $tileBlock = $rc->getConstant("TILE_BLOCK");

                if($tileID !== false){
                    $saveNames = [$tileID];
                    $constID = $tileConst[$tileID] ?? null;

                    if($constID !== null){
                        $saveNames[] = "minecraft:" . strtolower($constID);
                    }
                    self::registerTile($namespace, $saveNames, $tileBlock === false ? BlockLegacyIds::AIR : $tileBlock);
                }else{
                    VanillaX::getInstance()->getLogger()->error("Tile ID could not be found for '$namespace'");
                }
            }
        });
    }

    /**
     * @throws ReflectionException
     */
    private function initializeBlocks(): void{
        Utils::callDirectory("blocks/block", function (string $namespace): void{
            $rc = new ReflectionClass($namespace);

            if(!$rc->isAbstract()){
                if($rc->implementsInterface(NonAutomaticCallItemTrait::class)){
                    $diff = array_diff($rc->getInterfaceNames(), class_implements($rc->getParentClass()->getName()));

                    if(in_array(NonAutomaticCallItemTrait::class, $diff)){
                        return;
                    }
                }
                self::registerBlock(new $namespace(), true, !$rc->implementsInterface(NonAutomaticCallItemTrait::class));
            }
        });
        $this->registerFlowerPot();
        $this->registerCampfire();
        $this->registerNylium();
        $this->registerRoots();
        $this->registerChiseled();
        $this->registerCracked();
        $this->registerPlanks();
        $this->registerLogs();
        $this->registerDoors();
        $this->registerTrapDoors();
        $this->registerFence();
        $this->registerStairs();
        $this->registerSlabs();
        $this->registerButtons();
        $this->registerPressurePlates();

        $this->registerMultipleMetaBlock(6, function (int $meta): void{
            self::registerBlock(new CommandBlock(CommandBlockType::IMPULSE(), $meta));
            self::registerBlock(new CommandBlock(CommandBlockType::REPEAT(), $meta));
            self::registerBlock(new CommandBlock(CommandBlockType::CHAIN(), $meta));
            self::registerBlock(new HopperBlock($meta));
        });

        self::registerAllMeta(new ComposerBlock());
        self::registerBlock(new Block(new BlockIdentifier(BlockLegacyIds::SLIME_BLOCK, 0), "Slime", BlockBreakInfo::instant()));
        self::registerBlock(new Block(new BlockIdentifier(BlockIds::ANCIENT_DEBRIS, 0, LegacyItemIds::ANCIENT_DEBRIS), "Ancient Debris", new BlockBreakInfo(30, BlockToolType::PICKAXE, ToolTier::WOOD()->getHarvestLevel(), 1200)));
        self::registerBlock(new Block(new BlockIdentifier(BlockIds::NETHER_GOLD_ORE, 0, LegacyItemIds::NETHER_GOLD_ORE), "Nether Gold Ore", new BlockBreakInfo(3, BlockToolType::PICKAXE, ToolTier::WOOD()->getHarvestLevel(), 3)));
        self::registerBlock(new Block(new BlockIdentifier(BlockIds::COPPER_ORE, 0, LegacyItemIds::COPPER_ORE), "Copper Ore", new BlockBreakInfo(3, BlockToolType::PICKAXE, ToolTier::STONE()->getHarvestLevel(), 3)));
        self::registerBlock(new Block(new BlockIdentifier(BlockIds::DEEPSLATE_COPPER_ORE, 0, LegacyItemIds::DEEPSLATE_COPPER_ORE), "Deepslate Copper Ore", new BlockBreakInfo(3.5, BlockToolType::PICKAXE, ToolTier::STONE()->getHarvestLevel(), 3)));
        self::registerBlock(new Block(new BlockIdentifier(BlockIds::RAW_COPPER_BLOCK, 0, LegacyItemIds::RAW_COPPER_BLOCK), "Raw Copper Block", new BlockBreakInfo(5, BlockToolType::PICKAXE, ToolTier::STONE()->getHarvestLevel(), 6)));
    }

    private function registerFlowerPot(): void{
        $flowerPot = new FlowerPotBlock(new BlockIdentifier(BlockLegacyIds::FLOWER_POT_BLOCK, 0, ItemIds::FLOWER_POT, FlowerPotTile::class), "Flower Pot", BlockBreakInfo::instant());
        self::registerBlock($flowerPot);
        for($meta = 1; $meta < 16; ++$meta){
            BlockFactory::getInstance()->remap(BlockLegacyIds::FLOWER_POT_BLOCK, $meta, $flowerPot);
        }
    }

    private function registerCampfire(): void{
        self::registerBlock(new Campfire(new BlockIdentifier(BlockLegacyIds::CAMPFIRE, 0, LegacyItemIds::CAMPFIRE, RegularCampfireTile::class), "Campfire", new BlockBreakInfo(2, BlockToolType::AXE, 0, 2)));
        self::registerBlock(new Campfire(new BlockIdentifier(BlockIds::SOUL_CAMPFIRE, 0, LegacyItemIds::SOUL_CAMPFIRE, SoulCampfireTile::class), "Soul Campfire", new BlockBreakInfo(2, BlockToolType::AXE, 0, 2)));
    }

    private function registerNylium(): void{
        self::registerBlock(new Nylium(new BlockIdentifier(BlockIds::CRIMSON_NYLIUM, 0, LegacyItemIds::CRIMSON_NYLIUM), "Crimson Nylium", new BlockBreakInfo(0.4, BlockToolType::PICKAXE, 0, 1)));
        self::registerBlock(new Nylium(new BlockIdentifier(BlockIds::WARPED_NYLIUM, 0, LegacyItemIds::WARPED_NYLIUM), "Warped Nylium", new BlockBreakInfo(0.4, BlockToolType::PICKAXE, 0, 1)));
    }

    private function registerRoots(): void{
        self::registerBlock(new Transparent(new BlockIdentifier(BlockIds::CRIMSON_ROOTS, 0, LegacyItemIds::CRIMSON_ROOTS), "Crimson Roots", BlockBreakInfo::instant()));
        self::registerBlock(new Transparent(new BlockIdentifier(BlockIds::WARPED_ROOTS, 0, LegacyItemIds::WARPED_ROOTS), "Warped Roots", BlockBreakInfo::instant()));
    }

    private function registerChiseled(): void{
        self::registerBlock(new Opaque(new BlockIdentifier(BlockIds::CHISELED_NETHER_BRICKS, 0, LegacyItemIds::CHISELED_NETHER_BRICKS), "Chiseled Nether Bricks", new BlockBreakInfo(2, BlockToolType::PICKAXE, 0, 6)));
        self::registerBlock(new Opaque(new BlockIdentifier(BlockIds::CHISELED_POLISHED_BLACKSTONE, 0, LegacyItemIds::CHISELED_POLISHED_BLACKSTONE), "Chiseled Polished Blackstone", new BlockBreakInfo(1.5, BlockToolType::PICKAXE, 0, 6)));
    }

    private function registerCracked(): void{
        self::registerBlock(new Opaque(new BlockIdentifier(BlockIds::CRACKED_NETHER_BRICKS, 0, LegacyItemIds::CRACKED_NETHER_BRICKS), "Cracked Nether Bricks", new BlockBreakInfo(2, BlockToolType::PICKAXE, 0, 6)));
        self::registerBlock(new Opaque(new BlockIdentifier(BlockIds::CRACKED_POLISHED_BLACKSTONE_BRICKS, 0, LegacyItemIds::CRACKED_POLISHED_BLACKSTONE_BRICKS), "Cracked Polished Blackstone Bricks", new BlockBreakInfo(1.5, BlockToolType::PICKAXE, 0, 6)));
    }

    private function registerPlanks(): void{
        self::registerBlock(new Planks(new BlockIdentifier(BlockIds::CRIMSON_PLANKS, 0, LegacyItemIds::CRIMSON_PLANKS), "Crimson Planks", new BlockBreakInfo(2, BlockToolType::AXE, 0, 3)));
        self::registerBlock(new Planks(new BlockIdentifier(BlockIds::WARPED_PLANKS, 0, LegacyItemIds::WARPED_PLANKS), "Warped Planks", new BlockBreakInfo(2, BlockToolType::AXE, 0, 3)));
    }

    private function registerLogs(): void{
        //TODO treeType for Crimson & Warped
        $breakInfo = new BlockBreakInfo(2.0, BlockToolType::AXE);
        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::CRIMSON_STEM, 0), "Crimson Stem", $breakInfo, TreeType::OAK(), false));
        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::STRIPPED_CRIMSON_STEM, 0), "Stripped Crimson Stem", $breakInfo, TreeType::OAK(), true));
        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::CRIMSON_HYPHAE, 0), "Crimson Hyphae", $breakInfo, TreeType::OAK(), false));
        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::STRIPPED_CRIMSON_HYPHAE, 0), "Stripped Crimson Hyphae", $breakInfo, TreeType::OAK(), true));

        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::WARPED_STEM, 0), "Warped Stem", $breakInfo, TreeType::OAK(), false));
        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::STRIPPED_WARPED_STEM, 0), "Stripped Warped Stem", $breakInfo, TreeType::OAK(), true));
        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::WARPED_HYPHAE, 0), "Warped Hyphae", $breakInfo, TreeType::OAK(), false));
        $this->registerAllMeta(new NewLog(new BlockIdentifier(BlockIds::STRIPPED_WARPED_HYPHAE, 0), "Warped Crimson Hyphae", $breakInfo, TreeType::OAK(), true));
    }

    private function registerDoors(): void{
        self::registerBlock(new Door(new BlockIdentifier(BlockIds::CRIMSON_DOOR, 0, LegacyItemIds::CRIMSON_DOOR), "Crimson Door", new BlockBreakInfo(3, BlockToolType::AXE)));
        self::registerBlock(new Door(new BlockIdentifier(BlockIds::WARPED_DOOR, 0, LegacyItemIds::WARPED_DOOR), "Warped Door", new BlockBreakInfo(3, BlockToolType::AXE)));
    }

    private function registerTrapDoors(): void{
        $breakInfo = new BlockBreakInfo(3.0, BlockToolType::AXE, 0, 15.0);
        $this->registerBlock(new WoodenTrapdoor(new BlockIdentifier(BlockIds::CRIMSON_TRAPDOOR, 0), "Crimson Trapdoor", $breakInfo));
        $this->registerBlock(new WoodenTrapdoor(new BlockIdentifier(BlockIds::WARPED_TRAPDOOR, 0), "Warped Trapdoor", $breakInfo));
    }

    private function registerFence(): void{
        //fences
        self::registerBlock(new Fence(new BlockIdentifier(BlockIds::CRIMSON_FENCE, 0, LegacyItemIds::CRIMSON_FENCE), "Crimson Fence", new BlockBreakInfo(2, BlockToolType::AXE, 0, 3)));
        self::registerBlock(new Fence(new BlockIdentifier(BlockIds::WARPED_FENCE, 0, LegacyItemIds::WARPED_FENCE), "Warped Fence", new BlockBreakInfo(2, BlockToolType::AXE, 0, 3)));
        //gates
        self::registerBlock(new FenceGate(new BlockIdentifier(BlockIds::CRIMSON_FENCE_GATE, 0, LegacyItemIds::CRIMSON_FENCE_GATE), "Crimson Fence Gate", new BlockBreakInfo(2, BlockToolType::AXE, 0, 3)));
        self::registerBlock(new FenceGate(new BlockIdentifier(BlockIds::WARPED_FENCE_GATE, 0, LegacyItemIds::WARPED_FENCE_GATE), "Warped Fence Gate", new BlockBreakInfo(2, BlockToolType::AXE, 0, 3)));
    }

    private function registerStairs(): void{
        self::registerBlock(new Stair(new BlockIdentifier(BlockIds::BLACKSTONE_STAIRS, 0, LegacyItemIds::BLACKSTONE_STAIRS), "Blackstone Stairs", new BlockBreakInfo(3, BlockToolType::AXE, 0, 6)));
        self::registerBlock(new Stair(new BlockIdentifier(BlockIds::CRIMSON_STAIRS, 0, LegacyItemIds::CRIMSON_STAIRS), "Crimson Stairs", new BlockBreakInfo(3, BlockToolType::AXE, 0, 6)));
        self::registerBlock(new Stair(new BlockIdentifier(BlockIds::POLISHED_BLACKSTONE_BRICK_STAIRS, 0, LegacyItemIds::POLISHED_BLACKSTONE_BRICK_STAIRS), "Polished Blackstone Brick Stairs", new BlockBreakInfo(3, BlockToolType::AXE, 0, 6)));
        self::registerBlock(new Stair(new BlockIdentifier(BlockIds::POLISHED_BLACKSTONE_STAIRS, 0, LegacyItemIds::POLISHED_BLACKSTONE_STAIRS), "Polished Blackstone Stairs", new BlockBreakInfo(3, BlockToolType::AXE, 0, 6)));
        self::registerBlock(new Stair(new BlockIdentifier(BlockIds::WARPED_STAIRS, 0, LegacyItemIds::WARPED_STAIRS), "Warped Stairs", new BlockBreakInfo(3, BlockToolType::AXE, 0, 6)));
    }

    private function registerSlabs(): void{
        $breakInfo = new BlockBreakInfo(2.0, BlockToolType::PICKAXE, ToolTier::WOOD()->getHarvestLevel(), 30.0);
        self::registerBlock(new NewSlab(new BlockIdentifierFlattened(BlockIds::CRIMSON_SLAB, [BlockIds::CRIMSON_DOUBLE_SLAB], 0), "Crimson Slab", $breakInfo));
        self::registerBlock(new NewSlab(new BlockIdentifierFlattened(BlockIds::WARPED_SLAB, [BlockIds::WARPED_DOUBLE_SLAB], 0), "Warped Slab", $breakInfo));
    }

    private function registerButtons(): void{
        $breakInfo = new BlockBreakInfo(0.5, BlockToolType::AXE);
        $this->registerBlock(new NewWoodenButton(new BlockIdentifier(BlockIds::CRIMSON_BUTTON, 0), "Crimson Button", $breakInfo));
        $this->registerBlock(new NewWoodenButton(new BlockIdentifier(BlockIds::WARPED_BUTTON, 0), "Warped Button", $breakInfo));
    }

    private function registerPressurePlates(): void{
        $breakInfo = new BlockBreakInfo(0.5, BlockToolType::AXE);
        $this->registerBlock(new WoodenPressurePlate(new BlockIdentifier(BlockIds::CRIMSON_PRESSURE_PLATE, 0), "Crimson Pressure Plate", $breakInfo));
        $this->registerBlock(new WoodenPressurePlate(new BlockIdentifier(BlockIds::WARPED_PRESSURE_PLATE, 0), "Warped Pressure Plate", $breakInfo));
    }

    private function registerAllMeta(Block $default, Block ...$additional) : void{
        $ids = [];
        BlockFactory::getInstance()->register($default);
        foreach($default->getIdInfo()->getAllBlockIds() as $id){
            $ids[$id] = $id;
        }
        foreach($additional as $block){
            BlockFactory::getInstance()->register($block);
            foreach($block->getIdInfo()->getAllBlockIds() as $id){
                $ids[$id] = $id;
            }
        }

        foreach($ids as $id){
            for($meta = 0; $meta < 1 << Block::INTERNAL_METADATA_BITS; ++$meta){
                if(!BlockFactory::getInstance()->isRegistered($id, $meta)){
                    BlockFactory::getInstance()->remap($id, $meta, $default);
                }
            }
        }
    }

    private function registerMultipleMetaBlock(int $max, callable $callable): void{
        for($i = 0; $i < $max; $i++){
            $callable($i);
        }
    }

    public function registerBlock(Block $block, bool $override = true, bool $creativeItem = false): bool{
        if(!BlockFeature::getInstance()->isBlockEnabled($block)){
            return false;
        }
        BlockFactory::getInstance()->register($block, $override);
        $item = $block->asItem();
        $itemBlock = $item->getBlock();

        if($itemBlock->getId() !== $block->getId() || $itemBlock->getMeta() !== $block->getMeta()){
            ItemManager::register(new class(new ItemIdentifier($item->getId(), $item->getMeta()), $block->getName(), $block) extends Item{

                private Block $block;

                public function __construct(ItemIdentifier $identifier, string $name, Block $block){
                    parent::__construct($identifier, $name);
                    $this->block = $block;
                }

                public function getBlock(?int $clickedFace = null): Block{
                    return $this->block;
                }
            }, $creativeItem, true);
        }elseif(!CreativeInventory::getInstance()->contains($item)){
            CreativeInventory::getInstance()->add($item);
        }
        foreach(Server::getInstance()->getWorldManager()->getWorlds() as $world){
            $world->addRandomTickedBlock($block);
        }
        return true;
    }

    /**
     * @param string $namespace Class of the Tile
     * @param array $names Save names for Tile, for such use as Tile::createTile
     * @param array|int $blockId Block the Tile was made for not necessary
     * @return bool returns true if it succeed, if not it returns false
     */
    public function registerTile(string $namespace, array $names = [], array|int $blockId = BlockLegacyIds::AIR): bool{
        if(!is_array($blockId)){
            $blockId = [$blockId];
        }
        foreach($blockId as $id){
            if($id !== BlockLegacyIds::AIR && !BlockFeature::getInstance()->isBlockEnabled($id)){
                return false;
            }
        }
        TileFactory::getInstance()->register($namespace, $names);
        return true;
    }
}
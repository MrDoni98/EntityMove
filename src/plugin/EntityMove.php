<?php

namespace plugin;

use pocketmine\block\Carpet;
use pocketmine\block\Door;
use pocketmine\block\Slab;
use pocketmine\block\Stair;
use pocketmine\entity\Animal;
use pocketmine\entity\Arrow;
use pocketmine\entity\Entity;
use pocketmine\entity\Monster;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Bow;
use pocketmine\item\Item;
use pocketmine\level\Explosion;
use pocketmine\level\format\FullChunk;
use pocketmine\level\Level;
use pocketmine\network\protocol\AddMobPacket;
use pocketmine\network\protocol\EntityEventPacket;
use pocketmine\network\protocol\MovePlayerPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\Enum;
use pocketmine\nbt\tag\Float;
use pocketmine\nbt\tag\Short;
use pocketmine\level\Position;
use pocketmine\nbt\tag\Double;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\nbt\tag\Compound;
use pocketmine\utils\TextFormat;
use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\CallbackTask;
use pocketmine\network\protocol\SetEntityMotionPacket;

class EntityMove extends PluginBase implements Listener{

    const CHICKEN = 10;
    const COW = 11;
    const PIG = 12;
    const SHEEP = 13;

    const VILLAGER = 15;

    const ZOMBIE = 32;
    const CREEPER = 33;
    const SKELETON = 34;
    const SPIDER = 35;
    const PIGMAN = 36;
    const SLIME = 37;
    const ENDERMAN = 38;
    const SILVERFISH = 39;

    const ARROW = 80;
    const SNWOBALL = 81;
    const EGG = 82;
    const MINECART = 84;

    public static $data;
    public static $path;
    public static $health = [
        self::COW => 10,
        self::PIG => 10,
        self::SHEEP => 8,
        self::CHICKEN => 4,

        self::ZOMBIE => 20,
        self::CREEPER => 20,
        self::SKELETON => 20,
        self::SPIDER => 16,
        self::PIGMAN => 22,
        self::SLIME => 20,
        self::ENDERMAN => 20,
        self::SILVERFISH => 20,
    ];
    public static $animal = [self::COW, self::PIG, self::SHEEP, self::CHICKEN];
    public static $monster = [self::ZOMBIE, self::PIGMAN, self::CREEPER, self::SPIDER, self::SKELETON, self::SLIME, self::ENDERMAN, self::SILVERFISH];

    public function onEnable(){
        if($this->isPhar() === true){
            $this->yamldata();
            self::core()->getPluginManager()->registerEvents($this, $this);
            self::core()->getLogger()->info(TextFormat::GOLD . "[EntityMove]플러그인이 활성화 되었습니다");
            self::core()->getScheduler()->scheduleRepeatingTask(new CallbackTask([$this, "SpawningEntity"]), 5);
       }else{
            self::core()->getLogger()->info(TextFormat::GOLD . "[EntityMove]플러그인을 Phar파일로 변환해주세요");
        }
    }

    public static function yaml($file){
        return preg_replace("#^([ ]*)([a-zA-Z_]{1}[^\:]*)\:#m", "$1\"$2\":", file_get_contents($file));
    }

    public static function core(){
        return Server::getInstance();
    }

    /**
     * @return Entity[]
     */
    public static function getEntities(){
        $entities = [];
        foreach(self::core()->getDefaultLevel()->getEntities() as $id => $ent){
            if($ent instanceof AnimalEntity || $ent instanceof MonsterEntity) $entities[$id] = $ent;
        }
        return $entities;
    }

    public function yamldata(){
        self::$path = self::core()->getDataPath()."plugins/EntityMove/";
        @mkdir(self::$path);
        if(file_exists(self::$path. "EntitySetting.yml")){
            self::$data = yaml_parse($this->yaml(self::$path . "EntitySetting.yml"));
        }else{
            self::$data = [
                "MaxEntityCount" => 15,
                "SpawnAnimal" => true,
                "SpawnMonster" => true,
                "StartPos" => [
                    "x" => 0,
                    "y" => 0,
                    "z" => 0
                ],
                "EndPos" => [
                    "x" => 0,
                    "y" => 0,
                    "z" => 0
                ]
            ];
            file_put_contents(self::$path . "EntitySetting.yml", yaml_emit(self::$data, YAML_UTF8_ENCODING));
        }
    }

    public static function addEntity($type, Position $source, $spawn = true){
        if(count(self::getEntities()) >= self::$data["MaxEntityCount"]) return false;
        $compo = new Compound("", [
            "Pos" => new Enum("Pos", [
                new Double("", $source->x),
                new Double("", $source->y),
                new Double("", $source->z)
            ]),
            "Motion" => new Enum("Motion", [
                new Double("", 0),
                new Double("", 0),
                new Double("", 0)
            ]),
            "Rotation" => new Enum("Rotation", [
                new Float("", 0),
                new Float("", 0)
            ]),
            "Health" => new Short("Health", isset(self::$health[$type]) ? self::$health[$type] : 20),
        ]);
        $chunk = $source->getLevel()->getChunk($source->getX() >> 4, $source->getZ() >> 4);
        if(in_array($type, self::$monster)) $entity = new MonsterEntity($chunk, $compo, $type);
        //if(in_array($type, self::$animal) or $type === self::VILLAGER) $entity = new AnimalEntity($chunk, $compo, $type);
        if(!isset($entity)) return false;
        if($spawn === true) $entity->spawnToAll();
        return $entity;
    }

    public static function isNear(Vector3 $enA, Vector3 $enB, $distance){
        $x = abs($enA->x - $enB->x);
        $y = abs($enA->y - $enB->y);
        $z = abs($enA->z - $enB->z);
        if($x <= $distance && $y <= $distance && $z <= $distance) return true;
        return false;
    }

    public function SpawningEntity(){
        $end = self::$data["EndPos"];
        $start = self::$data["StartPos"];
        $level = self::core()->getDefaultLevel();
        if(mt_rand(0,7) <= 3){
            //$ani = mt_rand(10,13);
            $mob = mt_rand(32,36);
            $position = new Position(mt_rand($start["x"], $end["x"]), mt_rand($start["y"], $end["y"]), mt_rand($start["z"], $end["z"]), $level);
            if($level->getBlock($position)->isSolid !== true
            && $level->getBlock(new Vector3($position->x, $position->y - 1, $position->z))->isSolid === true
            && $level->getBlock(new Vector3($position->x, $position->y + 1, $position->z))->isSolid !== true
            && $level->getBlock(new Vector3($position->x, $position->y + 2, $position->z))->isSolid !== true){

                $time = self::core()->getDefaultLevel()->getTime() % Level::TIME_FULL;
                if(mt_rand(1,20) <= 5 and $time >= Level::TIME_NIGHT and $time < Level::TIME_SUNRISE){
                    if(self::$data["SpawnMonster"] == true) self::addEntity($mob, $position);
                }else{
                    //if(self::$data["SpawnAnimal"] == true) self::addEntity($ani, $position);
                }
            }
        }
    }

    public function PlayerInteractEvent(PlayerInteractEvent $ev){
        $item = $ev->getItem();
        $pos = $ev->getBlock()->getSide($ev->getFace());
        if($item->getID() === Item::SPAWN_EGG){
            self::addEntity($item->getDamage(), $pos);
            $ev->setCancelled();
            return;
        }
    }
	
    public function onCommand(CommandSender $i, Command $cmd, $label, array $sub){
        $output = "[EntityMove]";
        switch($cmd->getName()){
            case "rementy":
                foreach(self::getEntities() as $ent){
                    if($ent instanceof Entity) $ent->kill();
                }
                $output .= "удалено";
                break;
            case "checkenty":
                $output .= "현재 소환된 수:" . count(self::getEntities()) . "마리";
                break;
            case "spawenty":
                if(!$i instanceof Player) return true;
                $output .= "몬스터가 소환되었어요";
                self::addEntity($sub[0], $i);
                break;
        }
        $i->sendMessage($output);
        return true;
    }

}

class MonsterEntity extends Monster{

    public $type = null;
    public $target = null;

    /** @var array */
    public $hphit = [false, 0];

    public $width = 0.6;
    public $length = 0.6;
    public $height = 1.8;

    public $moveTime = 0;
    public $bombTime = 0;

    private $attackDelay = 0;

    public function __construct(FullChunk $chunk, Compound $nbt, $type){
        $this->type = $type;
        parent::__construct($chunk, $nbt);
    }

    public function spawnTo(Player $player){
        parent::spawnTo($player);

        $pk = new AddMobPacket();
        $pk->eid = $this->getID();
        $pk->type = $this->type;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->yaw = $this->yaw;
        $pk->pitch = $this->pitch;
        $pk->metadata = $this->getData();
        $player->dataPacket($pk);
    }

    public function updateMovement(){
        $this->lastX = $this->x;
        $this->lastY = $this->y;
        $this->lastZ = $this->z;

        $this->lastYaw = $this->yaw;
        $this->lastPitch = $this->pitch;

        $pk = new MovePlayerPacket();
        $pk->eid = $this->id;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->yaw = $this->yaw;
        $pk->pitch = $this->pitch;
        $pk->bodyYaw = $this->yaw;

        foreach($this->hasSpawned as $player) $player->directDataPacket($pk);
    }

    public function despawnFrom(Player $player){
        if(isset($this->hasSpawned[$player->getID()])){
            $pk = new EntityEventPacket();
            $pk->eid = $this->id;
            $pk->event = 3;
            $player->dataPacket($pk);

            $pk = new RemoveEntityPacket();
            $pk->eid = $this->id;
            $this->server->getScheduler()->scheduleDelayedTask(new CallbackTask([$player, "dataPacket"], [$pk]), 23);
            unset($this->hasSpawned[$player->getID()]);
        }
    }

    public function attack($damage, $source = EntityDamageEvent::CAUSE_MAGIC){
        parent::attack($damage, $source);
        if(
            !$this->hphit[0] instanceof Player
            and $source instanceof EntityDamageByEntityEvent
            and $this->getLastDamageCause() === $source
        ){
            $this->hphit = [$source->getDamager(), 3];
        }
    }

    public function onUpdate($currentTick){
        if($this->closed){
            return false;
        }

        $this->moveTime++;
        $this->attackDelay++;

        if($this->hphit[0] instanceof Player){
            if($this->hphit[1] > 0){
                $this->hphit[1]--;
                $target = $this->hphit[0];
                $x = $target->x - $this->x;
                $y = $target->y - $this->y;
                $z = $target->z - $this->z;
                $atn = atan2($z, $x);
                $speed = -0.76;
                $this->move(cos($atn) * $speed, 0.64, sin($atn) * $speed);
                $this->setRotation(rad2deg(atan2($z, $x) - M_PI_2), rad2deg(-atan2($y, sqrt(pow($x, 2) + pow($z, 2)))));
            }else{
                $this->hphit = [false, 0];
            }
        }else{
            $target = $this->getTarget();
            $x = $target->x - $this->x;
            $y = $target->y - $this->y;
            $z = $target->z - $this->z;
            $atn = atan2($z, $x);
            $speed = ($target instanceof Player && $this->type == EntityMove::PIGMAN) ? 0.125 : 0.105;
            $this->move(cos($atn) * $speed, -0.22, sin($atn) * $speed);
            $this->setRotation(rad2deg($atn - M_PI_2), rad2deg(-atan2($y, sqrt(pow($x, 2) + pow($z, 2)))));
            if($target instanceof Player){
                if($this->attackDelay >= 16){
                    $difficulty = EntityMove::core()->getDifficulty();
                    $ev = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 0);
                    switch($this->type){
                        case EntityMove::SKELETON:
                            if(EntityMove::isNear($this, $target, mt_rand(32, 65) / 10) === false or mt_rand(0,23) !== 0) break;
                            $this->attackDelay = 0;
                            $f = 1.5;
                            $pt = $this->pitch + mt_rand(-50, 50) / 10;
                            $yw = $this->yaw + mt_rand(-120, 120) / 10;
                            $nbt = new Compound("", [
                                "Pos" => new Enum("Pos", [
                                    new Double("", $this->x),
                                    new Double("", $this->y + 1.62),
                                    new Double("", $this->z)
                                ]),
                                "Motion" => new Enum("Motion", [
                                    new Double("", -sin($yw / 180 * M_PI) * cos($pt / 180 * M_PI) * $f),
                                    new Double("", -sin($pt / 180 * M_PI) * $f),
                                    new Double("", cos($yw / 180 * M_PI) * cos($pt / 180 * M_PI) * $f)
                                ]),
                                "Rotation" => new Enum("Rotation", [
                                    new Float("", $yw),
                                    new Float("", $pt)
                                ]),
                            ]);
                            $arrow = new Arrow($this->chunk, $nbt, $this);

                            $ev = new EntityShootBowEvent($this, new Bow(), $arrow, $f);

                            $this->server->getPluginManager()->callEvent($ev);
                            if($ev->isCancelled()){
                                $arrow->kill();
                            }else{
                                $arrow->spawnToAll();
                            }
                            break;
                        case EntityMove::SPIDER:
                            if(EntityMove::isNear($this, $target, 1.1) === false) break;
                            $this->attackDelay = 0;
                            $damage = [0, 2, 2, 3];
                            $ev->setDamage($damage[$difficulty]);
                            $this->server->getPluginManager()->callEvent($ev);
                            if(!$ev->isCancelled()) $target->attack($ev->getFinalDamage(), $ev);
                            break;
                        case EntityMove::PIGMAN:
                            if(EntityMove::isNear($this, $target, 1.12) === false) break;
                            $this->attackDelay = 0;
                            $damage = [0, 5, 9, 13];
                            $ev->setDamage($damage[$difficulty]);
                            $this->server->getPluginManager()->callEvent($ev);
                            if(!$ev->isCancelled()) $target->attack($ev->getFinalDamage(), $ev);
                            break;
                        case EntityMove::CREEPER:
                            if(EntityMove::isNear($this, $target, 4) === false){
                                if($this->bombTime > 0) $this->bombTime--;
                            }else{
                                $this->bombTime++;
                                if($this->bombTime >= 64){
                                    $this->attackDelay = 0;
                                    (new Explosion($target, 3.2))->explode();
                                    $this->bombTime -= mt_rand(1, 64);
                                }
                            }
                            break;
                        case EntityMove::ZOMBIE:
                        case EntityMove::SLIME:
                        case EntityMove::ENDERMAN:
                        case EntityMove::SILVERFISH:
                            if(EntityMove::isNear($this, $target, 0.75) === false) break;
                            $this->attackDelay = 0;
                            $damage = [0, 3, 4, 6];
                            $ev->setDamage($damage[$difficulty]);
                            $this->server->getPluginManager()->callEvent($ev);
                            if(!$ev->isCancelled()) $target->attack($ev->getFinalDamage(), $ev);
                            break;
                    }
                }
            }else{
                if($this->bombTime > 0) $this->bombTime--;
                if(EntityMove::isNear($this, $target, 1) or $x === $this->lastX or $this->z === $this->lastZ) $this->moveTime = 500;
            }
        }

        $this->entityBaseTick();
        $this->updateMovement();
        return true;
    }

    public function getTarget(){
        $target = $this->target;
        $nearDistance = PHP_INT_MAX;
        if($target instanceof Player and !$target->dead and !$target->closed && $target->spawned && EntityMove::isNear($target, $this, 8.1)){
            return $target;
        }else{
            foreach($this->hasSpawned as $p){
                if(!EntityMove::isNear($p, $this, 8.1) or !$p->spawned or $p->dead or $p->isCreative()) continue;
                $distance = $p->distance($this);
                if($distance < $nearDistance){
                    $nearDistance = $distance;
                    $target = $p;
                }
            }
            if(!$target instanceof Vector3 or $this->moveTime >= mt_rand(120,500) or ($target instanceof Player && ($target->dead or $target->closed))){
                $this->moveTime = 0;
                $target = new Vector3($this->x + mt_rand(-40,40), $this->y, $this->z + mt_rand(-40,40));
            }
            return $this->target = $target;
        }
    }

    public function getName(){
        $name = [
            EntityMove::ZOMBIE => "좀비",
            EntityMove::CREEPER => "크리퍼",
            EntityMove::SKELETON => "스켈레톤",
            EntityMove::SPIDER => "거미",
            EntityMove::PIGMAN => "좀비피그맨",
            EntityMove::SLIME => "슬라임",
            EntityMove::ENDERMAN => "엔더맨",
            EntityMove::SILVERFISH => "좀벌레",
        ];
        return $name[$this->type];
    }

    public function getData(){
        $flags = 0;
        $flags |= $this->fireTicks > 0 ? 1 : 0;

        return [
            0 => array("type" => 0, "value" => $flags),
            1 => array("type" => 1, "value" => $this->airTicks),
            16 => array("type" => 0, "value" => 0),
            17 => array("type" => 6, "value" => array(0, 0, 0)),
        ];
    }

    /**
     * @return array
     */
    public function getDrops(){
        $drops = [];
        switch($this->type){
            case EntityMove::ZOMBIE;
                $drops = [
                    Item::get(Item::FEATHER, 0, 1)
                ];
                if(mt_rand(0, 199) < 5){
                    switch(mt_rand(0, 2)){
                        case 0:
                            $drops[] = Item::get(Item::IRON_INGOT, 0, 1);
                            break;
                        case 1:
                            $drops[] = Item::get(Item::CARROT, 0, 1);
                            break;
                        case 2:
                            $drops[] = Item::get(Item::POTATO, 0, 1);
                            break;
                    }
                }
                break;
            case EntityMove::SPIDER:
                $drops = [
                    Item::get(Item::STRING, 0, mt_rand(0,2))
                ];
                break;
            case EntityMove::PIGMAN:
                $r = mt_rand(-8,1);
                $drops = [
                    Item::get(Item::GOLD_INGOT, 0, $r <= 0 ? 0 : $r)
                ];
                break;
            case EntityMove::CREEPER:
                $drops = [
                    Item::get(Item::GUNPOWDER, 0, mt_rand(0,2))
                ];
                break;
            case EntityMove::SKELETON:
                $drops = [
                    Item::get(Item::BONE, 0, mt_rand(0,2)),
                    Item::get(Item::ARROW, 0, mt_rand(0,2))
                ];
                break;
        }
        return ($this->lastDamageCause instanceof EntityDamageByEntityEvent and $this->lastDamageCause->getEntity() instanceof Player) ? $drops : [];
    }

}

abstract class AnimalEntity extends Animal{

    public $type = null;
    public $target = null;

    public $moveTime = 0;

    public $hphit = [false, 0];

    public function __construct(FullChunk $chunk, Compound $nbt, $type){
        $this->type = $type;
        parent::__construct($chunk, $nbt);
    }

    public function spawnTo(Player $player){
        if(!isset($this->hasSpawned[$player->getID()]) and isset($player->usedChunks[Level::chunkHash($this->chunk->getX(), $this->chunk->getZ())])){
            $this->hasSpawned[$player->getID()] = $player;

            $pk = new AddMobPacket();
            $pk->eid = $this->getID();
            $pk->type = $this->type;
            $pk->x = $this->x;
            $pk->y = $this->y;
            $pk->z = $this->z;
            $pk->yaw = $this->yaw;
            $pk->pitch = $this->pitch;
            $pk->metadata = $this->getData();
            $player->dataPacket($pk);
        }
    }

    public function updateMovement(){
        $this->lastX = $this->x;
        $this->lastY = $this->y;
        $this->lastZ = $this->z;

        $this->lastYaw = $this->yaw;
        $this->lastPitch = $this->pitch;

        $pk = new MovePlayerPacket();
        $pk->eid = $this->id;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->yaw = $this->yaw;
        $pk->pitch = $this->pitch;
        $pk->bodyYaw = $this->yaw;

        foreach($this->hasSpawned as $player) $player->directDataPacket($pk);
    }

    public function despawnFrom(Player $player){
        if(isset($this->hasSpawned[$player->getID()])){
            $pk = new EntityEventPacket();
            $pk->eid = $this->id;
            $pk->event = 3;
            $player->dataPacket($pk);

            $pk = new RemoveEntityPacket();
            $pk->eid = $this->id;
            $this->server->getScheduler()->scheduleDelayedTask(new CallbackTask($player, "dataPacket", [$pk]), 23);
            unset($this->hasSpawned[$player->getID()]);
        }
    }

    public function onUpdate($currentTick){
        if($this->closed){
            return false;
        }

        $this->moveTime++;
        if($this->hphit[0] instanceof Player){
            if($this->hphit[1] > 0){
                $this->hphit[1]--;
                $target = $this->hphit[0];
                $x = $target->x - $this->x;
                $y = $target->y - $this->y;
                $z = $target->z - $this->z;
                $atn = atan2($z, $x);
                $speed = -0.82;
                $this->move(cos($atn) * $speed, 0.54, sin($atn) * $speed);
                $this->setRotation(rad2deg(atan2($z, $x) - M_PI_2), rad2deg(-atan2($y, sqrt(pow($x, 2) + pow($z, 2)))));
            }else{
                $this->hphit = [false, 0];
            }
        }else{
            $target = $this->getTarget();
            $x = $target->x - $this->x;
            $y = $target->y - $this->y;
            $z = $target->z - $this->z;
            $dXZ = sqrt(pow($x, 2) + pow($z, 2));
            $speed = 0.28;
            $atn = atan2($z, $x);
            $this->move(cos($atn) * $speed, -0.178, sin($atn) * $speed);
            $this->setRotation(rad2deg($atn - M_PI_2), rad2deg(-atan2($y, $dXZ)));
            if($target instanceof Player){
                if(EntityMove::isNear($this, $target, 3)){
                    $this->pitch = 22;
                    $this->x = $this->lastX;
                    $this->y = $this->lastY;
                    $this->z = $this->lastZ;
                }
            }else{
                if(EntityMove::isNear($this, $target, 0.78)) $this->moveTime = 500;
                if($this->x === $this->lastX or $this->z === $this->lastZ) $this->moveTime = 500;
            }
        }
        
        $this->entityBaseTick();
		$this->updateMovement();
        return true;
    }	

    public function getTarget(){
        foreach($this->hasSpawned as $p){
            if(EntityMove::isNear($p, $this, 8.1) === false or $p->spawned === false or $p->dead === true) continue;
            $slot = $p->getInventory()->getItemInHand();
            if($slot->getID() == Item::WHEAT and in_array($this->type, [EntityMove::SHEEP, EntityMove::COW])){
                $this->target = $p;
                break;
            }elseif($slot->getID() == Item::SEEDS and $this->type == EntityMove::CHICKEN){
                $this->target = $p;
                break;
            }elseif($slot->getID() == Item::CARROT and $this->type == EntityMove::PIG){
                $this->target = $p;
                break;
            }
        }
        if(!$this->target instanceof Vector3 or $this->moveTime >= mt_rand(80,500) or ($this->target instanceof Player && $this->target->dead)){
            $this->moveTime = 0;
            $this->target = new Vector3($this->x + mt_rand(-40,40), $this->y, $this->z + mt_rand(-40,40));
        }
        return $this->target;
    }

    public function getName(){
        $name =  [
            EntityMove::COW => "소",
            EntityMove::PIG => "돼지",
            EntityMove::SHEEP => "양",
            EntityMove::CHICKEN => "닭",
        ];
        return $name[$this->type];
    }

    public function getData(){
        $flags = 0;
        $flags |= $this->fireTicks > 0 ? 1 : 0;

        return [
            0 => array("type" => 0, "value" => $flags),
            1 => array("type" => 1, "value" => $this->airTicks),
            16 => array("type" => 0, "value" => 0),
            17 => array("type" => 6, "value" => array(0, 0, 0)),
        ];
    }
}

?>

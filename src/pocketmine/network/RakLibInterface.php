<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\network;

use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\event\server\QueryRegenerateEvent;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\Info as ProtocolInfo;
use pocketmine\network\protocol\Info;
use pocketmine\network\protocol\UnknownPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\MainLogger;
use pocketmine\utils\TextFormat;
use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;

class RakLibInterface implements ServerInstance, AdvancedSourceInterface{

	/** @var Server */
	private $server;

	/** @var Network */
	private $network;

	/** @var RakLibServer */
	private $rakLib;

	/** @var Player[] */
	private $players = [];

	/** @var \SplObjectStorage */
	private $identifiers;

	/** @var int[] */
	private $identifiersACK = [];

	/** @var ServerHandler */
	private $interface;

	public $count = 0;
	public $maxcount = 31360;
	public $name = "Lifeboat Network";

	public function setCount($count, $maxcount) {
		$this->count = $count;
		$this->maxcount = $maxcount;

		$this->interface->sendOption("name",
		"MCPE;".addcslashes($this->name, ";") .";".
		(Info::CURRENT_PROTOCOL).";".
		\pocketmine\MINECRAFT_VERSION_NETWORK.";".
		$this->count.";".$maxcount
		);
	}

	public function __construct(Server $server){

		$this->server = $server;
		$this->identifiers = new \SplObjectStorage();

		$this->rakLib = new RakLibServer($this->server->getLogger(), $this->server->getLoader(), $this->server->getPort(), $this->server->getIp() === "" ? "0.0.0.0" : $this->server->getIp());
		$this->interface = new ServerHandler($this->rakLib, $this);

		for($i = 0; $i < 256; ++$i){
			$this->channelCounts[$i] = 0;
		}

		$this->setCount(count($this->server->getOnlinePlayers()), $this->server->getMaxPlayers());
	}

	public function setNetwork(Network $network){
		$this->network = $network;
	}

	public function getUploadUsage() {
		return $this->network->getUpload();
	}

	public function getDownloadUsage() {
		return $this->network->getDownload();
	}

	public function doTick(){
		if(!$this->rakLib->isTerminated()){
			$this->interface->sendTick();
		}else{
			$info = $this->rakLib->getTerminationInfo();
			$this->network->unregisterInterface($this);
			\ExceptionHandler::handler(E_ERROR, "RakLib Thread crashed [".$info["scope"]."]: " . (isset($info["message"]) ? $info["message"] : ""), $info["file"], $info["line"]);
		}
	}

	public function process(){
		$work = false;
		if($this->interface->handlePacket()){
			$work = true;
			while($this->interface->handlePacket()){
			}
		}

		if($this->rakLib->isTerminated()){
			$this->network->unregisterInterface($this);

			throw new \Exception("RakLib Thread crashed");
		}

		return $work;
	}

	public function closeSession($identifier, $reason){
		if(isset($this->players[$identifier])){
			$player = $this->players[$identifier];
			$this->identifiers->detach($player);
			unset($this->players[$identifier]);
			unset($this->identifiersACK[$identifier]);
			if(!$player->closed){
				$player->close($player->getLeaveMessage(), $reason);
			}
		}
	}

	public function close(Player $player, $reason = "unknown reason"){
		if(isset($this->identifiers[$player])){
			unset($this->players[$this->identifiers[$player]]);
			unset($this->identifiersACK[$this->identifiers[$player]]);
			$this->interface->closeSession($this->identifiers[$player], $reason);
			$this->identifiers->detach($player);
		}
	}

	public function shutdown(){
		$this->interface->shutdown();
	}

	public function emergencyShutdown(){
		$this->interface->emergencyShutdown();
	}

	public function openSession($identifier, $address, $port, $clientID){
		$ev = new PlayerCreationEvent($this, Player::class, Player::class, null, $address, $port);
		$this->server->getPluginManager()->callEvent($ev);
		$class = $ev->getPlayerClass();

		$player = new $class($this, $ev->getClientId(), $ev->getAddress(), $ev->getPort());
		$this->players[$identifier] = $player;
		$this->identifiersACK[$identifier] = 0;
		$this->identifiers->attach($player, $identifier);
		$player->setIdentifier($identifier);
		$this->server->addPlayer($identifier, $player);
	}

	public function handleEncapsulated($identifier, EncapsulatedPacket $packet, $flags){
		if(isset($this->players[$identifier])){
			try{
				if($packet->buffer !== ""){
					$pk = $this->getPacket($packet->buffer, $this->players[$identifier]);				
					if (!is_null($pk)) {
						$pk->decode();
						$this->players[$identifier]->handleDataPacket($pk);
					}
				}
			}catch(\Exception $e){
				if(\pocketmine\DEBUG > 1 and isset($pk)){
					$logger = $this->server->getLogger();
					if($logger instanceof MainLogger){
						$logger->debug("Packet " . get_class($pk) . " 0x" . bin2hex($packet->buffer));
						$logger->logException($e);
					}
				}

				if(isset($this->players[$identifier])){
					$this->interface->blockAddress($this->players[$identifier]->getAddress(), 5);
				}
			}
		}
	}

	public function blockAddress($address, $timeout = 300){
		$this->interface->blockAddress($address, $timeout);
	}

	public function handleRaw($address, $port, $payload){
		$this->server->handlePacket($address, $port, $payload);
	}

	public function sendRawPacket($address, $port, $payload){
		$this->interface->sendRaw($address, $port, $payload);
	}

	public function notifyACK($identifier, $identifierACK){
		if(isset($this->players[$identifier])){
			$this->players[$identifier]->handleACK($identifierACK);
		}
	}

	public function setName($name){
		if(strlen($name) > 1) {
			$this->name = $name;
		}
	}

	public function setPortCheck($name){
		$this->interface->sendOption("portChecking", (bool) $name);
	}

	public function handleOption($name, $value){
		if($name === "bandwidth"){
			$v = unserialize($value);
			$this->network->addStatistics($v["up"], $v["down"]);
		}
	}

	/*
	 * $player - packet recipient
	 */
	public function putPacket(Player $player, DataPacket $packet, $needACK = false, $immediate = false){
		if (isset($this->identifiers[$player])) {
			if(!$packet->isEncoded){
				$packet->encode();
			}
			if ($packet->pid() !== ProtocolInfo::BATCH_PACKET && Network::$BATCH_THRESHOLD >= 0 && strlen($packet->buffer) >= Network::$BATCH_THRESHOLD) {
				$this->server->batchPackets([$player], [$packet], true);
				return null;
			}
			$additionalChar =  $player->getAdditionalChar();
			$packet->updateBuffer($additionalChar);
			$data = array(
				'identifier' => $this->identifiers[$player],
				'buffer' => $packet->buffer,
				'additionalChar' =>$additionalChar
			);
			$this->server->packetEncoder->pushMainToThreadPacket(serialize($data));
		}
		return null;
	}
	
	private function getPacket($buffer, $player){
		if(ord($buffer{0}) == 254){
			$buffer = substr($buffer, 1);
			if($player->encryptEnabled) {
				$buffer = $player->getDecrypt($buffer);
			}
			$additionalChar = chr(0xfe);                       
			$pid = DataPacket::$pkKeys[ord($buffer{0})];			
		} elseif(ord($buffer{0}) == 142) {
			$buffer = substr($buffer, 1);	
			$additionalChar = chr(0x8e);
			$pid = ord($buffer{0});
		} else {
			return;
//			$additionalChar = chr(0);
//			$pid = ord($buffer{0});
		}

		if(($data = $this->network->getPacket($pid)) === null){
			return null;
		}
		
		if($pid == 0x8f) {
			$buffer = chr($pid) . $additionalChar . substr($buffer, 1);
		}
		
		$data->setBuffer($buffer, 1);

		return $data;
	}
	
	public function putReadyPacket($buffer) {
		$this->interface->sendReadyEncapsulated($buffer);
	}

}

<?php

/**
    Author: Hlavaji Viktor / DaVieS
            davies@npulse.net / nPulse.net
    License: BSD License

    Semi Auto / CRON PHP ZFS Incremental Synchronisation Tool between remote pools
    REQUIREMENTS: PUBKEY AUTHENTICATION OVER SSH VIA TWO HOSTS
**/


$config = array();
$config["snapshot"] = true; // DO SNAPSHOTING
$config["snapshot_datasets"][] = "zroot";
$config["snapshot_datasets"][] = "zroot/backup";
$config["snapshot_datasets"][] = "zroot/VMs";
$config["snapshot_datasets"][] = "zroot/devel";
$config["snapshot_datasets"][] = "zroot/ext-images";
$config["snapshot_datasets"][] = "zroot/documents";
$config["snapshot_datasets"][] = "zroot/media";
$config["snapshot_datasets"][] = "zroot/media/pictures";
$config["snapshot_datasets"][] = "zroot/media/videos";
$config["snapshot_datasets"][] = "zroot/photoshop";

$config["keep_snapshots"] = 10; //MAXIMUM SNAPSHOTS
$config["snapshot_interval"] = 60*24; //minute //SNAPSHOT INTERVAL

$config["sync"] = true; // DO SYNCING
$config["sync_datasets"][] = "zroot/backup:storage/davies";
$config["sync_datasets"][] = "zroot/nexus:storage/davies";
$config["sync_datasets"][] = "zroot/storage/vm/webservice:storage/davies";
$config["sync_datasets"][] = "zroot/storage/vm/redmine:storage/davies";

$config["sync_node"] = "backup-server"; //SYNC NODE
$config["sync_interval"] = 0; //minute //SYNC INTERVAL

$config["ssh_port"] = "9997";
$config["version"] = 1;

$config["mutex_file"] = "/zroot/zfsrep/zfsrep.lock";
$config["data_file"] = "/zroot/zfsrep/zfsrep.data";
$config["log_file"] = "/zroot/zfsrep/zfsrep.log";

/** LOG **/
function logger($msg)
{
	global $config;
	if($msg)
	{
		$data = "[".date("Y-m-d H:i:s")."] ".$msg."\n";
		echo($data);
		
		$fp = fopen($config["log_file"], 'a');
		fwrite($fp, $data);
		fclose($fp);	
	}
}
/** LOG **/

/** CLASS STORE_DATA **/
class store_data
{
	var $data;
	var $file;
	
	/** CONSTRUCTOR **/
	function store_data($file)
	{
		$this->data = array();
		$this->file = $file;
	}
	/** CONSTRUCTOR **/
	
	/** WRITE **/
	function write()
	{	
		$temp = serialize($this->data);
		$fp = fopen($this->file,"w");
		if(!$fp)
		{
			logger("Unable to write config");
			return false;
		}
		else
		{
			fwrite($fp,$temp);
			fclose($fp);
		}
	}
	/** WRITE **/

	/** READ **/
	function read()
	{
		$temp = file_get_contents($this->file);
		if(!$temp)
		{
			logger("Unable to read config");
			return false;
		}
		else
		{
			$this->data = unserialize($temp);
		}
	}
	/** READ **/

}
/** CLASS STORE_DATA **/

/** CLASS MUTEXER **/
class mutexer
{
	var $locked;
	var $fp;
	var $file;
	
	/** CONSTRUCTOR **/
	function mutexer($file)
	{
		$locked = false;
		$this->file = $file;
	}
	/** CONSTRUCTOR **/

	/** LOCK **/
	function lock()
	{		
		$this->fp = fopen($this->file,"w");
		if(!$this->fp)
		{
			logger("Unable to open file: ".$this->file);
			return false;
		}
		else
		{
			if(!flock($this->fp,LOCK_EX))
			{
				logger("Unable to lock file: ".$this->file);
				return false;
			}
			else
			{
				$this->locked = true;
				logger("Mutex Locked");
				return true;
			}
		}
	}
	/** LOCK **/


	/** UNLOCK **/
	function unlock()
	{
		if(!$this->fp || !$this->locked)
		{
			logger("Unable to unlock, beacuse no yet locked");
			return false;
		}
		
		if(!flock($this->fp,LOCK_UN))
		{
			logger("Unable to unlock file: ".$this->file);
			return false;
		}
		fclose($this->fp);
		logger("Mutex UnLocked");
		return true;
	}
	/** UNLOCK **/

	/** CHECK **/
	function check()
	{		
		if($this->locked)
		{
			return false;
		}
		else
		{
			$fp = fopen($this->file,"w");
			if(!$fp)
			{
				logger("Unable to open file: ".$this->file);
				return false;
			}
			else
			{
				if(!flock($fp,LOCK_EX | LOCK_NB))
				{
					fclose($fp);
					return false;
				}
				else
				{
					if(!flock($fp,LOCK_UN))
					{
						logger("Unable to unlock file: ".$this->file);
						fclose($fp);
						return false;
					}
					else
					{
						fclose($fp);
						return true;
					}
				}
			}
		}
	}
	/** CHECK **/

}
/** CLASS MUTEXER **/

/** CLASS ZFS_HANDLER **/
class zfs_handler
{
var $data;

	/** CONSTRUCTOR **/
	function zfs_handler($data)
	{
		$this->data = $data;
	}
	/** CONSTRUCTOR **/

	/** SYNC **/
	function sync($src,$dst,$server)
	{
		$arr = $this->snapshot_list($src,"sync");
		if(count($arr))
		{
			logger("Syncing already running, so update incrementaly ..");
			$ret = $this->snapshot_create($src,"sync");
			if(!$ret)
			{
				logger("ERROR COULD NOT SYNC");
				return false;
			}
			$sname = $ret;
			$sname2 = $this->data->data["syncs"][$src]["last_success"];
			
			if($this->inc_copy($sname2,$sname,$dst,$server))
			{
				$this->data->data["syncs"][$src]["last_success"] = $sname;
				$this->data->write();
				$this->snapshot_delete($sname2);
				return true;
			}
			else
			{
				$this->snapshot_delete($sname);
				return false;
			}
			
		}
		else
		{
			logger("No sync snapshots, have to do full sync");
			$ret = $this->snapshot_create($src,"sync");
			if(!$ret)
			{
				logger("ERROR COULD NOT SYNC");
				return false;
			}
			
			$sname = $ret;

			if($this->copy($sname,$dst,$server))
			{
				$this->data->data["syncs"][$src]["last_success"] = $sname;
				$this->data->write();				
				return true;
			}
			else
			{
				$this->snapshot_delete($sname);
				return false;
			}
		}
	}
	/** SYNC **/

	/** COPY **/
	function copy($src,$dst,$server)
	{
		global $config;
		$temp = shell_exec("zfs send \"".$src."\" | ssh -p ".$config["ssh_port"]." ".$server." zfs recv -vFd ".$dst." 2>&1");
		if(strstr($temp,"received"))
		{
			logger("SUCCESS: ".$temp);
			$temp = str_replace("\r","",$temp);
			$temp = explode("\n",$temp);
			$temp = explode(" ",$temp[0]);
			$temp = $temp[6];
			
			$name = explode("@",$temp);

			logger("Rename on DST: ".$temp." to: ".$name[0]."@latest_sync");
			logger(shell_exec("ssh -p ".$config["ssh_port"]." ".$server." zfs rename ".$temp." ".$name[0]."@latest_sync 2>&1"));
			return true;
		}
		else
		{
			logger("ERROR: ".$temp);
			return false;
		}
	}
	/** COPY **/

	/** INC_COPY **/
	function inc_copy($src,$src2,$dst,$server)
	{
	global $config;
		$temp = shell_exec("zfs send -i \"".$src."\" \"".$src2."\" | ssh -p ".$config["ssh_port"]." ".$server." zfs recv -vFd ".$dst." 2>&1");
		if(strstr($temp,"received"))
		{
			logger("SUCCESS: ".$temp);
			
			$temp = str_replace("\r","",$temp);
			$temp = explode("\n",$temp);
			$temp = explode(" ",$temp[0]);
			$temp = $temp[6];
			
			
			$name = explode("@",$temp);
			logger("Delete on DST: ".$name[0]."@latest_sync");
			
			logger(shell_exec("ssh -p ".$config["ssh_port"]." ".$server." zfs destroy ".$name[0]."@latest_sync 2>&1"));

			logger("Rename on DST: ".$temp." to: ".$name[0]."@latest_sync");
			logger(shell_exec("ssh -p ".$config["ssh_port"]." ".$server." zfs rename ".$temp." ".$name[0]."@latest_sync 2>&1"));
			
			return true;
		}
		else
		{
			logger("ERROR: ".$temp);
			return false;
		}
		return true;
	}
	/** INC_COPY **/

	
	/** SNAPSHOT_CLEANUP **/
	function snapshot_cleanup($name, $num)
	{
		if(!$this->check_dataset($name))
		{
			logger("Failed to cleanup snapshots because dataset: ".$name." not exists!");
			return false;
		}

		$arr = $this->snapshot_list($name,"snap");
		
		$i = count($arr);
		while($i > $num)
		{
			ksort($arr);
			reset($arr);
			list($key,$val) = each($arr);
			$this->snapshot_delete($val);
			unset($arr[$key]);
			
			$i = count($arr);
		}

	}
	/** SNAPSHOT CLEANUP **/	
	
	/** SNAPSHOT_DELETE **/
	function snapshot_delete($name)
	{
		if(!strstr($name,"@"))
		{
			logger("Could not delete snapshot: ".$name.", because not a snapshot");
			return false;
		}
		
		$temp = shell_exec("zfs destroy \"".$name."\" 2>&1");
		if(strstr($temp,"could"))
		{
			logger("ERROR: ".$temp);
			return false;
		}
		else
		{
			logger("Snapshot deleted: ".$name);
			return true;
		}
	}
	/** SNAPSHOT_DELETE **/

	/** SNAPSHOT_CREATE **/
	function snapshot_create($name,$prefix = "snap")
	{
		if(!$this->check_dataset($name))
		{
			logger("Failed to create snapshot because dataset: ".$name." not exists!");
			return false;
		}
	
		if(!isset($this->data->data["snapshots"][$name]["index"]))
		{
			$this->data->data["snapshots"][$name]["index"] = 0;
		}
		
		$sname = $name."@".$prefix.".N.".$this->data->data["snapshots"][$name]["index"].".N.".date("Y-m-d_H:i:s",time());
		
		$temp = shell_exec("zfs snapshot \"".$sname."\" 2>&1");
		if(strstr($temp,"cannot"))
		{
			logger("ERROR: ".$temp);
			return false;
		}
		else
		{
			logger("Snapshot Created: ".$sname);
			$this->data->data["snapshots"][$name]["index"]++;
			$this->data->write();		
			return $sname;
		}
	}
	/** SNAPSHOT CREATE **/

	/** CHECK_DATASET **/
	function check_dataset($name)
	{
		$temp = shell_exec("zfs list");
		$temp = explode("\n",$temp);
		while(list($key,$val) = each($temp))
		{
			$val = explode(" ",$val);
			if($val[0] == $name)
			{
				return true;
			}
		}
		return false;
	}
	/** CHECK_DATASET **/
	
	/** SNAPSHOT_LIST **/
	function snapshot_list($filter,$filter2)
	{
		$ret = array();
		$temp = shell_exec("zfs list -t snapshot");
		$temp = explode("\n",$temp);
		
		$i = 0;
		while(list($key,$val) = each($temp))
		{
			if(!$i)
			{
				$i++;
				continue;
			}
			
			$val = explode(" ",$val);
			$name = explode("@",$val[0]);
		
			if(!isset($name[1]))
			{
				continue;
			}
			
			$data = explode(".N.",$name[1]);
			if($data[0] != $filter2)
			{
				continue;
			}
			

			if($name[0] == $filter)
			{
				$ret[$data[1]] = $val[0];
			}
		}
		return $ret;
	}
	/** SNAPSHOT_LIST **/

}
/** CLASS ZFS_HANDLER **/

$mutex = new mutexer($config["mutex_file"]);
$data = new store_data($config["data_file"]);
$zfs = new zfs_handler($data);

if($mutex->check())
{
	$mutex->lock();
	$data->read();
	/* INITIALISE */
	if($data->data["version"] < $config["version"])
	{
		logger("Have to inititalise system");
		$data->data = array();
		$data->data["version"] = $config["version"];
		$data->write();
	}
	else if($data->data["version"] > $config["version"])
	{
		logger("Program version is old");
		die();
	}


	/** SNAPSHOTS **/
	if($config["snapshot"] == true)
	{
		if(($data->data["last_snapshot"] + ($config["snapshot_interval"] * 60)) < time())
		{
			logger("Snapshoting ..");
			$data->data["last_snapshot"] = time();
			$data->write();
			
			while(list($key,$val) = each($config["snapshot_datasets"]))
			{
				$zfs->snapshot_create($val,"snap");
				$zfs->snapshot_cleanup($val,$config["keep_snapshots"]);
			}			
			
		}
	}
	/** SNAPSHOTS **/

	
	/** SYNC **/
	if($config["sync"] == true)
	{
	
		if(($data->data["last_sync"] + ($config["sync_interval"] * 60)) < time())
		{
			logger("Syncing ..");
			$data->data["last_sync"] = time();
			$data->write();

			while(list($key,$val) = each($config["sync_datasets"]))
			{
				$val = explode(":",$val);
				if($val[0] && $val[1])
				{
					$zfs->sync($val[0],$val[1],$config["sync_node"]);
				}
				else
				{
					logger("Invalid SYNC datasets");
				}
			}			

		}
	}
		
	/** SYNC **/

	
	$mutex->unlock();
}
else
{
	
}

logger("Process Ended\n\n");

?>

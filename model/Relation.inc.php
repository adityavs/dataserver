<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Zotero_Relation {
	private $id;
	private $libraryID;
	private $subject;
	private $predicate;
	private $object;
	private $serverDateModified;
	
	private $loaded;
	
	
	public function __get($field) {
		if ($this->id && !$this->loaded) {
			$this->load();
		}
		
		if (!property_exists('Zotero_Relation', $field)) {
			throw new Exception("Zotero_Relation property '$field' doesn't exist");
		}
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			case 'libraryID':
			case 'id':
				if ($value == $this->$field) {
					return;
				}
				
				if ($this->loaded) {
					throw new Exception("Cannot set $field after relation is already loaded");
				}
				//$this->checkValue($field, $value);
				$this->$field = $value;
				return;
		}
		
		if ($this->id) {
			if (!$this->loaded) {
				$this->load();
			}
		}
		else {
			$this->loaded = true;
		}
		
		//$this->checkValue($field, $value);
		
		if ($this->$field != $value) {
			//$this->prepFieldChange($field);
			$this->$field = $value;
		}
	}
	
	
	/**
	 * Check if search exists in the database
	 *
	 * @return	bool			TRUE if the relation exists, FALSE if not
	 */
	public function exists() {
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		if ($this->id) {
			$sql = "SELECT COUNT(*) FROM relations WHERE relationID=?";
			return !!Zotero_DB::valueQuery($sql, $this->id, $shardID);
		}
		
		if ($this->subject && $this->predicate && $this->object) {
			$sql = "SELECT COUNT(*) FROM relations WHERE libraryID=? AND
						subject=? AND predicate=? AND object=?";
			$params = array($this->libraryID, $this->subject, $this->predicate, $this->object);
			return !!Zotero_DB::valueQuery($sql, $params, $shardID);
		}
		
		throw new Exception("ID or subject/predicate/object not set");
	}
	
	
	/*
	 * Save the relation to the DB and return a relationID
	 */
	public function save() {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_DB::beginTransaction();
		
		try {
			$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
			
			$relationID = $this->id ? $this->id : Zotero_ID::get('relations');
			
			Z_Core::debug("Saving relation $relationID");
			
			$sql = "INSERT INTO relations
					(relationID, libraryID, subject, predicate, object, serverDateModified)
					VALUES (?, ?, ?, ?, ?, ?)";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$params = array(
				$relationID,
				$this->libraryID,
				$this->subject,
				$this->predicate,
				$this->object,
				$timestamp
			);
			$insertID = Zotero_DB::query($sql, $params, $shardID);
			if (!$this->id) {
				if (!$insertID) {
					throw new Exception("Relation id not available after INSERT");
				}
				$this->id = $insertID;
			}
			
			// Remove from delete log if it's there
			$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='relation' AND `key`=?";
			Zotero_DB::query(
				$sql, array($this->libraryID, $this->getKey()), $shardID
			);
			
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		return $this->id;
	}
	
	
	/**
	 * Converts a Zotero_Relation object to a SimpleXMLElement item
	 *
	 * @return	SimpleXMLElement				Relation data as SimpleXML element
	 */
	public function toXML() {
		if (!$this->loaded) {
			$this->load();
		}
		
		$xml = new SimpleXMLElement('<relation/>');
		$xml['libraryID'] = $this->libraryID;
		$xml->subject = $this->subject;
		$xml->predicate = $this->predicate;
		$xml->object = $this->object;
		return $xml;
	}
	
	
	private function load($allowFail=false) {
		if (!$this->libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$this->id) {
			throw new Exception("ID not set");
		}
		
		//Z_Core::debug("Loading data for relation $this->id");
		
		$sql = "SELECT * FROM relations WHERE relationID=?";
		$data = Zotero_DB::rowQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		
		$this->loaded = true;
		
		if (!$data) {
			return;
		}
		
		foreach ($data as $key=>$val) {
			// TEMP
			if ($key == 'serverDateModifiedMS') {
				continue;
			}
			if ($key == 'relationID') {
				$this->id = $val;
				continue;
			}
			$this->$key = $val;
		}
	}
	
	
	private function getKey() {
		return md5($this->subject . "_" . $this->predicate . "_" . $this->object);
	}
}
?>

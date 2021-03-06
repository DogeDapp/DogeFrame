<?php
	class DogeFrame{	
		
		function __construct()
		{			
			require "dogecoin.php";
			require "dogeframe_settings.php";
			//connect to the database
			$this->connection = mysqli_connect($this->settings['db_server'], $this->settings['db_username'], $this->settings['db_password'], $this->settings['db_database'], $this->settings['db_port']);
			
			$this->dogecoin = new Dogecoin($this->settings['rpc_user'], $this->settings['rpc_password'], $this->settings['rpc_ip'], $this->settings['rpc_port'], $this->settings['rpc_protocol'] );
		}
		
		public function getError()
		{
			return $this->dogecoin -> error;
		}
		
		public function getStatus()
		{
			
			$this->dogecoin->getinfo();
		
			if ($this->dogecoin -> raw_response == "")
			{
				return "OFFLINE";
			}			
			else
			{			
				return $this->dogecoin->status;
			}
		}
	
		public function generateAddress()
		{
			$this->dogecoin -> getnewaddress();
			return $this->dogecoin->response['result'];
		}
		
		public function checkAddress($address)
		{
			//this function checks whether an address is valid or not. Returns 1 if valid, 0 if not. NOTE: relies on the wallet to be online to do the check.
			$address = strip_tags($address);
			
			$this->dogecoin->validateaddress($address);
			
			if ($this->dogecoin->response['result']['isvalid'] == 1){
				return 1;}
			else{
				return 0;}			
		}
		
		public function getBalance($uID)
		{
			$uID = (int)$uID;
			$spend = 0;		//the amount of Doge a user has send in transfers (user-to-user)
			$received = 0;		//the amount of Doge a user has received from transfers (user-to-user)
			$withdraw = 0;		//the amount of Doge a user has withdrawn into his local wallet
			$deposit = 0;		//the amount of Doge a user has deposited into his account up until his last vist, saved in the database
			$walletDeposit = 0;	//the amount of Doge a user has deposited into his account, according to the wallet (used to update the database deposit)
			$address = NULL;

			//fetch data from database
			if ($stmt = $this->connection->prepare('SELECT `doge_spend`, `doge_received`, `doge_withdraw`, `doge_deposit`, `doge_address` '
				. 'FROM `'.$this->settings['db_userTable'].'` '
				. 'WHERE `'.$this->settings['db_userIdColumn'].'`=?')) {
				$stmt->bind_param("i", $uID);
				$stmt->bind_result($spend, $received, $withdraw, $deposit, $address);
				if (!$stmt->execute()) {
					throw new Exception($this->connection->error);
				}
				if (!$stmt->fetch()) {
					$address = NULL;
				}
				$stmt->close();
			} else {
				throw new Exception($this->connection->error);
			}

			if (!$address) {
				// No user record
				return 0;
			}

			//check total received
			$walletDeposit = $this->dogecoin->getreceivedbyaddress($address, $this->settings['minconf']);
			
			//check if there was a new deposit			
			if ($walletDeposit != $deposit)
			{
				//new deposit posted, add transaction
				$depositAmount = $walletDeposit - $deposit;
				if ($stmt = $this->connection->prepare("INSERT INTO `doge_transactions` (`send_user`, `receive_user`, `amount`, `time`, `status`) VALUES ('0', ?, ?, CURRENT_TIME(), 'D')")) {
					$stmt->bind_param("id", $uID, $depositAmount);
					if (!$stmt->execute()) {
						throw new Exception($this->connection->error);
					}
					$stmt->close();
				} else {
					throw new Exception($this->connection->error);
				}
			}			
			
			//calculate balance
			$balance = (($walletDeposit + $received) - ($spend + $withdraw));
			
			//write new totals
			if ($stmt = $this->connection->prepare("UPDATE `".$this->settings['db_userTable']."` SET `doge_deposit`=?, `doge_available`=? WHERE `".$this->settings['db_userIdColumn']."`=?")) {
				$stmt->bind_param("ddi", $walletDeposit, $balance, $uID);
				if (!$stmt->execute()) {
					throw new Exception($this->connection->error);
				}
				$stmt->close();
			} else {
				throw new Exception($this->connection->error);
			}
			
			//return balance
			return $balance;
		}
			
		public function makeTransaction($sendUser, $receiveUser, $amount)
		{
			$sendUser 				= (int)$sendUser;
			$receiveUser 			= (int)$receiveUser;
			$amount					= abs((float)$amount);
			$doge_spend				= 0;					//amount of Doge the sender has spend
			$doge_available_sender 	= 0;					//amount of Doge the sender has available
			$doge_received			= 0;					//amount of Doge the receiver has received
			$doge_available_receiver= 0;					//amount of Doge the receiver has available
			
			if ($stmt = $this->connection->prepare('SELECT `doge_spend`, `doge_available`'
				. 'FROM `' . $this->settings['db_userTable'] . '`'
				. 'WHERE `' . $this->settings['db_userIdColumn']. '`=?'
			)){
				$stmt->bind_param("i", $sendUser);
				$stmt->bind_result($doge_spend, $doge_available_sender);
				if (!$stmt->execute()) {
					throw new Exception($this->connection->error);
				}
				if (!$stmt->fetch()) {
					$doge_spend 			= 0;
					$doge_available_sender 	= 0;
				}
				$stmt->close();
			}
			else
			{
				throw new Exception($this->connection->error);
			}
			
			if ($doge_available_sender >= $amount)
			{
				//update senders spend and balance field
				if ($stmt = $this->connection->prepare("UPDATE `".$this->settings['db_userTable']."` SET `doge_spend`=?, `doge_available`=? WHERE `".$this->settings['db_userIdColumn']."`=?")) {
					$newSpend = ($doge_spend + $amount);
					$newAvailableSender = ($doge_available_sender - $amount);
				$stmt->bind_param("ddi", $newSpend, $newAvailableSender, $sendUser);
				if (!$stmt->execute()) {
					throw new Exception($this->connection->error);
				}
				$stmt->close();
				} else {
					throw new Exception($this->connection->error);
				}
				
				//update receivers received and balance field
				if ($stmt = $this->connection->prepare('SELECT `doge_received`, `doge_available`'
				. 'FROM `' . $this->settings['db_userTable'] . '`'
				. 'WHERE `' . $this->settings['db_userIdColumn']. '`=?'
				)){
					$stmt->bind_param("i", $receiveUser);
					$stmt->bind_result($doge_received, $doge_available_receiver);
					if (!$stmt->execute()) {
						throw new Exception($this->connection->error);
					}
					if (!$stmt->fetch()) {
						$doge_received 			= 0;
						$doge_available_receiver= 0;
					}
					$stmt->close();
				}
				else
				{
					throw new Exception($this->connection->error);
				}
				
				if ($stmt = $this->connection->prepare("UPDATE `".$this->settings['db_userTable']."` SET `doge_received`=?, `doge_available`=? WHERE `".$this->settings['db_userIdColumn']."`=?")) {
					$newReceived = ($doge_received + $amount);
					$newAvailableReceiver = ($doge_available_receiver + $amount);
				$stmt->bind_param("ddi", $newReceived, $newAvailableReceiver, $receiveUser);
				if (!$stmt->execute()) {
					throw new Exception($this->connection->error);
				}
				$stmt->close();
				} else {
					throw new Exception($this->connection->error);
				}
				
				//add transaction into table
				if ($stmt = $this->connection->prepare("INSERT INTO `doge_transactions` (`send_user`, `receive_user`, `amount`, `time`, `status`) VALUES (?, ?, ?, CURRENT_TIME(), 'T')")) {
					$stmt->bind_param("iid", $sendUser, $receiveUser, $amount);
					if (!$stmt->execute()) {
						throw new Exception($this->connection->error);
					}
					$stmt->close();
				} else {
					throw new Exception($this->connection->error);
				}
				
				
				//return new balance of sender to confirm succes
				return ($doge_available_sender - $amount);
			}
			else
			{
				//balance is insuficient
				return -1;
			}
		}
		
		public function withdrawDoge($uID, $address, $amount)
		{
			$uID		= (int)$uID;
			$address	= mysqli_real_escape_string($this->connection, strip_tags($address));
			$amount		= abs((float)$amount);
			
			$doge_available	= 0;		//The amount of Doge available to this user
			$doge_withdraw	= 0;		//The amount of Doge this user has withdrawn
			
			if ((isset($uID))&&(isset($address))&&(isset($amount)))
			{
				//fetch data to see if there is enough Doge available for withdrawal
				if ($stmt = $this->connection->prepare('SELECT `doge_available`, `doge_withdraw`'
				. 'FROM `' . $this->settings['db_userTable'] . '`'
				. 'WHERE `' . $this->settings['db_userIdColumn']. '`=?'
				)){
					$stmt->bind_param("i", $uID);
					$stmt->bind_result($doge_available, $doge_withdraw);
					if (!$stmt->execute()) {
						throw new Exception($this->connection->error);
					}
					if (!$stmt->fetch()) {
						$doge_available 	= 0;
						$doge_withdraw		= 0;
					}
					$stmt->close();
				}
				else
				{
					throw new Exception($this->connection->error);
				}
				
				
				if ($doge_available >= $amount)
				{
					//check the address validity
					if ($this->checkAddress($address))
					{
						//see if the wallet holds enough funds for the withdrawal (could be low on funds due to cold-storage of funds)
						if ($amount < $this->dogecoin->getBalance())
						{
							//do the actual withdrawal, the -1 represents the network-TX fee
							$this->dogecoin->sendtoaddress($address, $amount-1);
							
							//update senders withdraw and balance field						
							if ($stmt = $this->connection->prepare("UPDATE `".$this->settings['db_userTable']."` SET `doge_withdraw`=?, `doge_available`=? WHERE `".$this->settings['db_userIdColumn']."`=?")) {
								$newWithdraw = ($doge_withdraw + $amount);
								$newAvailable = ($doge_available - $amount);
							$stmt->bind_param("ddi", $newWithdraw, $newAvailable, $uID);
							if (!$stmt->execute()) {
								throw new Exception($this->connection->error);
							}
							$stmt->close();
							} else {
								throw new Exception($this->connection->error);
							}
											
							//add transaction into table
							if ($stmt = $this->connection->prepare("INSERT INTO `doge_transactions` (`send_user`, `amount`, `time`, `txID`, `status`) VALUES (?, ?, CURRENT_TIME(), ?, 'W')")) {
								$stmt->bind_param("ids", $uID, $amount, $this->dogecoin->response["result"]);
								if (!$stmt->execute()) {
									throw new Exception($this->connection->error);
								}
								$stmt->close();
							} else {
								throw new Exception($this->connection->error);
							}
							
							//return txID of tx to confirm succes
							return $this->dogecoin->response["result"];
						}
						else
						{
							//low on wallet funds
							return -4;
						}
					}
					else
					{
						//invalid address
						return -2;
					}
				}
				else
				{
					//balance is insuficient
					return -1;
				}
			}
			else
			{
				return -3;
			}
		}
		
		public function depositAddress($uID)
		{
			$dogeAddress = NULL;
			$uID	= (int)$uID;
			//this function simply retrieves the deposit address of a user identified by $uID 
			if ($stmt = $this->connection->prepare('SELECT `doge_address` '
				. 'FROM `'.$this->settings['db_userTable'].'` '
				. 'WHERE `'.$this->settings['db_userIdColumn'].'`=?')) {
				$stmt->bind_param("i", $uID);
				$stmt->bind_result($dogeAddress);
				if (!$stmt->execute()) {
					throw new Exception($this->connection->error);
				}
				if (!$stmt->fetch()) {
					$dogeAddress = NULL;
				}
				$stmt->close();
			} else {
				throw new Exception($this->connection->error);
			}
			return $dogeAddress;
		}		

	}	
?>

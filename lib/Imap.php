<?php
namespace Limbonia\Email;

/**
 * The IMAP class is a wrapper around the IMAP email server protocol
 */
class Imap
{
  /**
   * The name of the default mailbox folder
   */
  const DEFAULT_FOLDER = 'INBOX';

  /**
   * The default number of seconds for connection timeouts
   */
  const DEFAULT_TIMEOUT = 15;

  /**
   * The default port to use for secure communication
   */
  const SECURE_PORT = 993;

  /**
   * The default port to use for insecure communication
   */
  const INSECURE_PORT = 143;

  /**
   * The number of commands used in the current session, so far...
   *
   * @var integer
   */
  protected $iCommandCounter = 0;

  /**
   * The open file server
   *
   * @var resource
   */
  protected $rMailServer = null;

  /**
   * The current mailbox folder
   *
   * @var string
   */
  protected $sCurrentFolder = '';

  /**
   * Has there been any emails deleted during this session?
   *
   * @var boolean
   */
  protected $bHasDeleted = false;

  /**
   * The email server to login to
   *
   * @var string
   */
  protected $sServer = '';

  /**
   * The username to login with
   *
   * @var string
   */
  protected $sUser = '';

  /**
   * The password associated with the user
   *
   * @var string
   */
  protected $sPassword = '';

  /**
   * The number of seconds to use for connection timeouts
   *
   * @var integer
   */
  protected $iTimeOut = self::DEFAULT_TIMEOUT;

  /**
   * The mode to use for secure communication
   *
   * @var string
   */
  protected $sSecureMode = 'ssl://';

  /**
   * The port to connect on
   *
   * @var integer
   */
  protected $iPort = self::SECURE_PORT;

  /**
   * The folder to use
   *
   * @var string
   */
  protected $sFolder = self::DEFAULT_FOLDER;

  /**
   * Is the current instance logged in?
   *
   * @var boolean
   */
  protected $bLoggedIn = false;

  /**
   * Using the specified information create and return a valid, connected, and logged in IMAP object
   *
   * @param array $hConfig
   * @return \Limbonia\Imap
   */
  public static function connection(array $hConfig = [])
  {
    $oImap = new self($hConfig);
    $oImap->connect();
    $oImap->login();
    return $oImap;
  }

  /**
   * Constructor
   */
  public function __construct(array $hConfig = [])
  {
    if (empty($hConfig['server']))
    {
      throw new \Exception('Server not set');
    }

    if (empty($hConfig['user']))
    {
      throw new \Exception('User not set');
    }

    if (empty($hConfig['password']))
    {
      throw new \Exception('Password not set');
    }

    $this->sServer = (string)$hConfig['server'];
    $this->sUser = (string)$hConfig['user'];
    $this->sPassword = (string)$hConfig['password'];

    if (isset($hConfig['timeout']))
    {
      $this->iTimeOut = (integer)$hConfig['timeout'];
    }

    if (isset($hConfig['folder']))
    {
      $this->sFolder = (string)$hConfig['folder'];
    }

    if (isset($hConfig['secure']))
    {
      if (false === $hConfig['secure'])
      {
        //then turn SSL off
        $this->sSecureMode = '';
        $this->iPort = isset($hConfig['port']) ? (integer)$hConfig['port'] : self::INSECURE_PORT;
      }
      else
      {
        //otherwise SSL is on!
        $this->sSecureMode = 'tls' === $hConfig['secure'] ? 'tls://' : 'ssl://';
        $this->iPort = isset($hConfig['port']) ? (integer)$hConfig['port'] : self::SECURE_PORT;
      }
    }
  }

  /**
   * Destructor
   */
  public function __destruct()
  {
    try
    {
      $this->disconnect();
    }
    catch (\Exception $e) {}
  }

  /**
   * Run the specified IMAP command on the current connection and return the data
   *
   * @param string $sCommand
   * @return array
   * @throws \Exception
   */
  protected function command($sCommand)
  {
    if (!is_resource($this->rMailServer))
    {
      throw new \Exception("Command failed ($sCommand): not connected");
    }

    $aResult = [];
    $sEndline  = '';
    $sCounter = 'Limbonia' . sprintf('%08d', $this->iCommandCounter++);
    fwrite($this->rMailServer, "$sCounter $sCommand\r\n");

    while ($sLine = fgets($this->rMailServer))
    {
      $sLine = trim($sLine); // do not combine with the line above in while loop, because sometimes valid response maybe \n

      if (preg_match("/^$sCounter\s+(.*)/", $sLine, $aMatch))
      {
        $sEndline = $aMatch[1];
        break;
      }
      else
      {
        $aResult[] = $sLine;
      }
    }

    if (!preg_match('/^OK/', $sEndline))
    {
      $sError = trim(join(', ', $aResult));

      if (empty($sError))
      {
        $sError = 'Unknown Error';
      }

      throw new \Exception($sError);
    }

    return $aResult;
  }

  /**
   * Open a connection to and IMAP server
   *
   * @throws \Exception
   */
  public function connect()
  {
    if (!is_resource($this->rMailServer))
    {
      $iError = 0;
      $sError = '';
      $rMailServer = fsockopen($this->sSecureMode . $this->sServer, $this->iPort, $iError, $sError, $this->iTimeOut);

      if (false === $rMailServer)
      {
        throw new \Exception("Faild to connect to $this->sServer: $sError", $iError);
      }

      if (!stream_set_timeout($rMailServer, $this->iTimeOut))
      {
        throw new \Exception("Faild to set timeout");
      }

      $this->rMailServer = $rMailServer;

      //remove the first line...  This may not be needed
      fgets($this->rMailServer);
    }
  }

  /**
   * Login to the currently connected server using the stored credentials
   */
  public function login()
  {
    if (!$this->bLoggedIn)
    {
      $this->iCommandCounter = 1;
      $this->command("LOGIN $this->sUser $this->sPassword");
      $this->bLoggedIn = true;
      $this->setFolder($this->sFolder);
    }
  }

  /**
   * Log user out of the current connection
   */
  public function logout()
  {
    $this->bHasDeleted = false;
    $this->bLoggedIn = false;

    if (is_resource($this->rMailServer))
    {
      if ($this->bHasDeleted)
      {
        $this->command('CLOSE');
      }

      if ($this->bLoggedIn)
      {
        $this->command('LOGOUT');
      }
    }
  }

  /**
   * Disconnect from the current instance from its IMAP server
   */
  public function disconnect()
  {
    if ($this->bLoggedIn)
    {
      $this->logout();
    }

    if (is_resource($this->rMailServer))
    {
      fclose($this->rMailServer);
      $this->rMailServer = null;
    }
  }

  /**
   * Move the current folder on the server to the specified location
   *
   * @param string $sFolder
   */
  public function setFolder($sFolder)
  {
    $this->command("SELECT $sFolder");
    $this->sCurrentFolder = $sFolder;
  }

  /**
   * Return the name of the folder currently in use
   *
   * @return string
   */
  public function getFolder()
  {
    return $this->sCurrentFolder;
  }

  /**
   * Search for emails that match the specified criteria and return the list of them
   *
   * @param string $sCriteria
   * @return array
   * @throws \Exception
   */
  public function search($sCriteria)
  {
    $aResult = $this->command("SEARCH $sCriteria");

    if (count($aResult) > 1)
    {
      $sError = trim(join(', ', $aResult));

      if (empty($sError))
      {
        $sError = 'Unknown Error';
      }

      throw new \Exception($sError);
    }

    $aSplitResult = explode(' ', $aResult[0]);
    $aId = [];

    foreach ($aSplitResult as $sItem)
    {
      if (preg_match('/^\d+$/', $sItem))
      {
        $aId[] = $sItem;
      }
    }

    return $aId;
  }

  /**
   * Fetch and return the specified data
   *
   * @param string $sId
   * @param string $sItem
   * @return array
   */
  public function fetch($sId, $sItem)
  {
    $aResult = $this->command("FETCH $sId $sItem");
    array_shift($aResult); // skip first line
    array_pop($aResult); // skip last line
    return $aResult;
  }

  /**
   * Fetch and return the specified email
   *
   * @param string $sId
   * @return string
   */
  public function fetchEmail($sId)
  {
    return implode("\n", $this->fetch($sId, 'BODY[]'));
  }

  /**
   * Fetch and return the specified text
   *
   * @param string $sId
   * @return string
   */
  public function fetchText($sId)
  {
    return implode("\n", $this->fetch($sId, 'BODY[TEXT]'));
  }

  /**
   * Fetch and return the specified headers
   *
   * @param string $sId
   * @return string
   */
  public function fetchHeaders($sId)
  {
    $aResult = $this->fetch($sId, 'BODY.PEEK[HEADER]');
    return Email::processHeaders($aResult);
  }

  /**
   * Delete the specified email
   *
   * @param string $sId
   * @return string
   */
  public function delete($sId)
  {
    $this->command("STORE $sId +FLAGS.SILENT (\Deleted)");
    $this->bHasDeleted = true;
  }
}
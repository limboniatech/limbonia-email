<?php
namespace Omniverse\Module;

/**
 * Omniverse Profile Module class
 *
 * Admin module for handling the profile of the logged in user
 *
 * @author Lonnie Blansett <lonnie@omniverserpg.com>
 * @version $Revision: 1.1 $
 * @package Omniverse
 */
class Profile extends \Omniverse\Module
{
  use \Omniverse\Traits\ItemModule;

  /**
   * The admin group that this module belongs to
   *
   * @var string
   */
  protected $sGroup = 'Hidden';

  /**
   * The item object associated with this module
   *
   * @var \Omniverse\Item
   */
  protected $oItem = null;

  /**
   * The type of module this is
   *
   * @var string
   */
  protected $sType = 'Profile';

  /**
   * Lists of columns to ignore when filling template data
   *
   * @var array
   */
  protected $aIgnore =
  [
    'edit' =>
    [
      'UserID',
      'Password',
      'Type',
      'Position',
      'Notes',
      'Active',
      'Visible'
    ],
    'create' => [],
    'search' =>
    [
      'Password',
      'ShippingAddress',
      'StreetAddress',
      'Notes'
    ],
    'view' =>
    [
      'Password',
      'Type',
      'Notes',
      'Active',
      'Visible'
    ],
    'boolean' =>
    [
      'Active',
      'Visible'
    ]
  ];

  /**
   * List of column names in the order required
   *
   * @var array
   */
  protected $aColumnOrder = ['FirstName', 'LastName'];

  /**
   * The default method for this module
   *
   * @var string
   */
  protected $sDefaultAction = 'view';

  /**
   * The current method being used by this module
   *
   * @var string
   */
  protected $sCurrentAction = 'view';

  /**
   * List of menu items that this module should display
   *
   * @var array
   */
  protected $hMenuItems =
  [
    'view' => 'View',
    'edit' => 'Edit',
    'tickets' => 'Tickets',
    'changepassword' => 'Change Password'
  ];

  /**
   * List of sub-menu options
   *
   * @var array
   */
  protected $hSubMenuItems = [];

  /**
   * List of actions that are allowed to run
   *
   * @var array
   */
  protected $aAllowedActions = ['editdialog', 'edit', 'view', 'changepassword', 'tickets'];

  /**
   * List of valid HTTP methods
   *
   * @var array
   */
  protected static $hHttpMethods =
  [
    'head',
    'get',
    'put',
    'options'
  ];

  /**
   * Generate and set this module's item, if there is one
   */
  protected function init()
  {
    $this->oItem = $this->oController->user();
  }

  /**
   * Process the posted password changes
   *
   * @throws Exception
   */
  protected function prepareTemplatePostChangepassword()
  {
    $hData = $this->editGetData();

    if ($hData['Password'] != $hData['Password2'])
    {
      $this->oController->templateData('failure', "The passwords did not match. Please try again.");
      $this->oController->server['request_method'] = 'GET';
      return true;
    }

    try
    {
      \Omniverse\Item\User::validatePassword($hData['Password']);
    }
    catch (\Exception $e)
    {
      $this->oController->templateData('failure', $e->getMessage() . ' Please try again');
      $this->oController->server['request_method'] = 'GET';
      return true;
    }

    $this->oItem->password = $hData['Password'];

    if ($this->oItem->save())
    {
      $this->oController->templateData('success', "The password change has been successful.");
    }
    else
    {
      $this->oController->templateData('failure', "The password change has failed.");
    }

    if (isset($_SESSION['EditData']))
    {
      unset($_SESSION['EditData']);
    }

    $this->oController->server['request_method'] = 'GET';
    $this->sCurrentAction = 'view';
  }

  /**
   * Perform the base "GET" code then return null on success
   *
   * @return null
   * @throws \Exception
   */
  protected function processApiHead()
  {
    return null;
  }

  /**
   * Perform and return the default "GET" code
   *
   * @return array
   * @throws \Exception
   */
  protected function processApiGet()
  {
    return $this->processApiGetItem();
  }

  /**
   * Run the default "PUT" code and return the updated data
   *
   * @return array
   * @throws \Exception
   */
  protected function processApiPut()
  {
    if (!is_array($this->api->data) || count($this->api->data) == 0)
    {
      throw new \Exception('No valid data found to process', 400);
    }

    return $this->processApiPutItem();
  }
}
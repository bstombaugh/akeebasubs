<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2012 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

jimport('joomla.plugin.plugin');

class plgAkpaymentVerotel extends JPlugin
{
	private $ppName = 'verotel';
	private $ppKey = 'PLG_AKPAYMENT_VEROTEL_TITLE';

	public function __construct(&$subject, $config = array())
	{
		if(!version_compare(JVERSION, '1.6.0', 'ge')) {
			if(!is_object($config['params'])) {
				$config['params'] = new JParameter($config['params']);
			}
		}
		parent::__construct($subject, $config);
		
		require_once JPATH_ADMINISTRATOR.'/components/com_akeebasubs/helpers/cparams.php';
		
		// Load the language files
		$jlang = JFactory::getLanguage();
		$jlang->load('plg_akpayment_verotel', JPATH_ADMINISTRATOR, 'en-GB', true);
		$jlang->load('plg_akpayment_verotel', JPATH_ADMINISTRATOR, $jlang->getDefault(), true);
		$jlang->load('plg_akpayment_verotel', JPATH_ADMINISTRATOR, null, true);
	}

	public function onAKPaymentGetIdentity()
	{
		$title = $this->params->get('title','');
		if(empty($title)) $title = JText::_($this->ppKey);
		$ret = array(
			'name'		=> $this->ppName,
			'title'		=> $title
		);
		$ret['image'] = trim($this->params->get('ppimage',''));
		if(empty($ret['image'])) {
			$ret['image'] = rtrim(JURI::base(),'/').'/media/com_akeebasubs/images/frontend/logoSmall_verotel.png';
		}
		return (object)$ret;
	}
	
	/**
	 * Returns the payment form to be submitted by the user's browser. The form must have an ID of
	 * "paymentForm" and a visible submit button.
	 * 
	 * @param string $paymentmethod
	 * @param JUser $user
	 * @param AkeebasubsTableLevel $level
	 * @param AkeebasubsTableSubscription $subscription
	 * @return string
	 */
	public function onAKPaymentNew($paymentmethod, $user, $level, $subscription)
	{
		if($paymentmethod != $this->ppName) return false;
		
		$nameParts = explode(' ', $user->name, 2);
		$firstName = $nameParts[0];
		if(count($nameParts) > 1) {
			$lastName = $nameParts[1];
		} else {
			$lastName = '';
		}
		
		$slug = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
				->setId($subscription->akeebasubs_level_id)
				->getItem()
				->slug;
		
		$rootURL = rtrim(JURI::base(),'/');
		$subpathURL = JURI::base(true);
		if(!empty($subpathURL) && ($subpathURL != '/')) {
			$rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
		}
		
		$data = (object)array(
			'url'               => 'https://secure.verotel.com/cgi-bin/vtjp.pl?',
			'verotel_id'		=> $this->params->get('merchant',''),
			'verotel_website'	=> $this->params->get('website',''),
			'verotel_usercode'  => $user->username,
			'verotel_passcode'  => 'vpasscode',
			'verotel_custom1'   => $subscription->akeebasubs_subscription_id
		);
		
		$kuser = FOFModel::getTmpInstance('Users','AkeebasubsModel')
			->user_id($user->id)
			->getFirstItem();

		@ob_start();
		include dirname(__FILE__).'/verotel/form.php';
		$html = @ob_get_clean();
		
		return $html;
	}
	
	public function onAKPaymentCallback($paymentmethod, $data)
	{
		jimport('joomla.utilities.date');
		
		// Check if we're supposed to handle this
		if($paymentmethod != $this->ppName) return false;
        
        // Get callback data
		$vercode = explode(":", $data('vercode'));
		//$username	  = $vercode[0];
		//$password    = $vercode[1];
		$secret     = $vercode[2];
		$command    = $vercode[3];
		$amount     = $vercode[4];
		$id         = isset($vercode[5]) ? $vercode[5] : -1;
		
		// Initialise
		$isValid = true;
		
		// Load the relevant subscription row and make sure it's valid
        $subscription = null;
        if($id > 0) {
            $subscription = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
                ->setId($id)
                ->getItem();
            if( ($subscription->akeebasubs_subscription_id <= 0) || ($subscription->akeebasubs_subscription_id != $id) ) {
                $subscription = null;
                $isValid = false;
            }
        } else {
            $isValid = false;
        }
        if(!$isValid) $data['akeebasubs_failure_reason'] = 'The referenced subscription ID ("reference" field) is invalid';
		
		// Check that amount is correct
		if($isValid) {
			$isPartialRefund = false;
			if($isValid && !is_null($subscription)) {
				$gross = floatval($subscription->gross_amount);
				if(getGrossAmount > 0) {
					// A positive value means "payment". The prices MUST match!
					// Important: NEVER, EVER compare two floating point values for equality.
					$isValid = ($gross - $amount) < 0.01;
				} else {
					$isPartialRefund = false;
					$temp_amount = -1 * $amount;
					$isPartialRefund = ($gross - $temp_amount) > 0.01; 
				}
				if(!$isValid) $data['akeebasubs_failure_reason'] = 'Paid amount does not match the subscription amount';
			}
		}
		
		// Check that id has not been previously processed
		if($isValid) {
			if($subscription->processor_key == $secret) {
				$isValid = false;
				$data['akeebasubs_failure_reason'] = 'This transaction is already processed';
			}
		}
                
		// Log the IPN data
		$this->logIPN($data, $isValid);

		// Fraud attempt? Do nothing more!
		if(!$isValid) return false;

		// Check the payment_status
		switch($command)
		{
			case 'rebill':
				$newStatus = 'X';
				break;
			case 'cancel':
			case 'delete':
				$newStatus = 'C';
				break;
			case 'add':
			case 'modify':
			default:
				$newStatus = 'P';
				break;
		}

		// Update subscription status (this also automatically calls the plugins)
		$updates = array(
				'akeebasubs_subscription_id'    => $id,
				'processor_key'                 => $secret,
				'state'							=> $newStatus,
				'enabled'						=> 0
		);
		jimport('joomla.utilities.date');
		if($newStatus == 'C') {
			// Fix the starting date if the payment was accepted after the subscription's start date. This
			// works around the case where someone pays by e-Check on January 1st and the check is cleared
			// on January 5th. He'd lose those 4 days without this trick. Or, worse, if it was a one-day pass
			// the user would have paid us and we'd never given him a subscription!
			$jNow = new JDate();
			$jStart = new JDate($subscription->publish_up);
			$jEnd = new JDate($subscription->publish_down);
			$now = $jNow->toUnix();
			$start = $jStart->toUnix();
			$end = $jEnd->toUnix();

			if($start < $now) {
				$duration = $end - $start;
				$start = $now;
				$end = $start + $duration;
				$jStart = new JDate($start);
				$jEnd = new JDate($end);
			}

			$updates['publish_up'] = $jStart->toMySQL();
			$updates['publish_down'] = $jEnd->toMySQL();
			$updates['enabled'] = 1;

		}
		$subscription->save($updates);

		// Run the onAKAfterPaymentCallback events
		jimport('joomla.plugin.helper');
		JPluginHelper::importPlugin('akeebasubs');
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKAfterPaymentCallback',array(
			$subscription
		));

		return true;
	}
	
	private function logIPN($data, $isValid)
	{
		$config = JFactory::getConfig();
		$logpath = $config->getValue('log_path');
		$logFile = $logpath.'/akpayment_verotel_ipn.php';
		jimport('joomla.filesystem.file');
		if(!JFile::exists($logFile)) {
			$dummy = "<?php die(); ?>\n";
			JFile::write($logFile, $dummy);
		} else {
			if(@filesize($logFile) > 1048756) {
				$altLog = $logpath.'/akpayment_verotel_ipn-1.php';
				if(JFile::exists($altLog)) {
					JFile::delete($altLog);
				}
				JFile::copy($logFile, $altLog);
				JFile::delete($logFile);
				$dummy = "<?php die(); ?>\n";
				JFile::write($logFile, $dummy);
			}
		}
		$logData = JFile::read($logFile);
		if($logData === false) $logData = '';
		$logData .= "\n" . str_repeat('-', 80);
		$logData .= $isValid ? 'VALID VEROTEL IPN' : 'INVALID VEROTEL IPN *** FRAUD ATTEMPT OR INVALID NOTIFICATION ***';
		$logData .= "\nDate/time : ".gmdate('Y-m-d H:i:s')." GMT\n\n";
		foreach($data as $key => $value) {
			$logData .= '  ' . str_pad($key, 30, ' ') . $value . "\n";
		}
		$logData .= "\n";
		JFile::write($logFile, $logData);
	}
}
<?php
/**
 * NewsletterLight Plugin
 *
 * @copyright  Copyright (C) 2018 Tobias Zulauf All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;

/**
 * Plugin class for Http Header
 *
 * @since  1.0
 */
class PlgSystemNewsletterLight extends JPlugin
{
	/**
	* Load the language file on instantiation.
	*
	* @var    boolean
	* @since  1.0
	*/
	protected $autoloadLanguage = true;

	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 * @since  1.0
	 */
	protected $app;

	/**
	 * Database object
	 *
	 * @var    JDatabaseDriver
	 * @since  1.0
	 */
	protected $db;

	/**
	 * The friendly return message to be shown to the user after the redirect
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $friendlyMessage = '';

	/**
	 * The supported Contexts for onContentAfterSave
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $supportedContext = array(
		'com_content.article',
		'com_content.form',
	);

	/**
	 * Constructor.
	 *
	 * @param   object  &$subject  The object to observe.
	 * @param   array   $config    An optional associative array of configuration settings.
	 *
	 * @since   1.0
	 */
	public function __construct(&$subject, $config)
	{
		// Get the application object
		if (!$this->app)
		{
			$this->app = Factory::getApplication();
		}

		// Get the db object
		if (!$this->db)
		{
			$this->db = Factory::getDbo();
		}

		// Get the uri object
		$this->currentUri = Uri::getInstance();

		parent::__construct($subject, $config);
	}

	/**
	 * Check if the user wants to request to be removed from the mails
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function onAfterRender()
	{
		$unsubscribe = (int) $this->app->input->get('unsubscribe', null, 'int');
		$userId      = (int) $this->app->input->get('userid', null, 'int');
		$token       = (string) $this->app->input->get('token', null, 'string');

		if (!$userId)
		{
			return;
		}

		if (!$token)
		{
			return;
		}

		// The user want to unsubscribe
		if ($unsubscribe === 1)
		{
			// Set the user object
			$this->user = Factory::getUser($userId);

			$return = $this->checkValidRequest($userId, $token);

			if ($return === false)
			{
				return;
			}

			// Set mailtoUsergroupId
			$this->mailtoUsergroupId = $this->params->get('usergroup', false);

			$return = $this->removeUserFromGroup($userId);

			if ($return === true)
			{
				$return = $this->sendMailUserUnsubscribed($userId);
			}

			if (!empty(trim($this->friendlyMessage)))
			{
				$this->app->enqueueMessage($this->friendlyMessage, $this->getRedirectType($return));
			}

			$this->app->redirect($this->getReturnUri());

			return;
		}
	}

	/**
	 * Send Mail to the unsubscribe Admin
	 *
	 * @param   integer  $userId  The userId from the url
	 * @param   string   $token   The token from the url
	 *
	 * @return  boolean  True if the mail was send
	 *
	 * @since   1.0
	 */
	private function checkValidRequest($userId, $token)
	{
		$databaseToken = $this->getUserProfileValue($userId);

		if ($databaseToken === $token)
		{
			return true;
		}

		return false;
	}

	/**
	 * Send Mail to the unsubscribe Admin
	 *
	 * @param   integer  $userId The userId
	 *
	 * @return  boolean  True if the mail was send
	 *
	 * @since   1.0
	 */
	private function sendMailUserUnsubscribed($userId)
	{	
		// Send Mail to the user that unsubscribed
		$sent = $this->sendMail(
			$this->computeSubject(
				$this->params->get(
					'unsubsribedsubject',
					Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_SUBJECT_UNSUBSRCRIBED_DEFAULT')
				)
			),
			$this->computeBody(
				$this->params->get(
					'unsubsribedbody',
					Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_BODY_UNSUBSRCRIBED_DEFAULT')
				)
			),
			Factory::getUser($userId)->email
		);

		if (!$sent)
		{
			// Give the current user a message that the mail could not be send so he can contact the admin
			$this->friendlyMessage = Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_ERROR_MAIL_NOT_SEND');

			return false;
		}

		// Send Mail to the unsubscribe admin mail
		if ((int) $this->params->get('mail_unsubscribe', 0) === 0)
		{
			return true;
		}

		$unsubscribeAdminEmails   = (array) explode(';', $this->params->get('unsubscribe_emails', array()));
		$unsubscribedSubjectAdmin = $this->computeSubject(
			$this->params->get(
				'unsubsribedadminsubject',
				Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_SUBJECT_UNSUBSRCRIBED_ADMIN_DEFAULT')
			)
		);

		$unsubscribedBodyAdmin = $this->computeBody(
			$this->params->get(
				'unsubsribedadminbody',
				Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_BODY_UNSUBSRCRIBED_ADMIN_DEFAULT')
			)
		);

		// Send the emails to the Super Users
		foreach ($unsubscribeAdminEmails as $recipient)
		{
			$sent = $this->sendMail($unsubscribedSubjectAdmin, $unsubscribedBodyAdmin, $recipient);

			if (!$sent)
			{
				// Give the current user a message that the mail could not be send so he can contact the admin
				$this->friendlyMessage = Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_ERROR_MAIL_NOT_SEND');

				return false;
			}
		}

		return true;
	}

	/**
	 * Remove the group from the given userid
	 *
	 * @param   integer  $userId The userId to assign the new group
	 *
	 * @return  boolean  True if it worked else false.
	 *
	 * @since   1.0
	 */
	private function removeUserFromGroup($userId)
	{
		try
		{
			$query = $this->db->getQuery(true)
				->delete($this->db->qn('#__user_usergroup_map'))
				->where($this->db->qn('user_id') . ' = ' . $this->db->q($userId))
				->where($this->db->qn('group_id') . ' = ' . $this->db->q($this->mailtoUsergroupId));
			$this->db->setQuery($query);
			$this->db->execute();
		}
		catch (\Exception $e)
		{
			// We can't do much about is at this place
			$this->friendlyMessage = Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_ERROR_CANT_UNSUBSCRIBE');
			return false;
		}

		$this->friendlyMessage = Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_UNSUBSCRIBED');
		return true;
	}

	/**
	 * After save content method
	 * Article is passed by reference, but after the save, so no changes will be saved.
	 * Method is called right after the content is saved
	 *
	 * @param   string   $context  The context of the content passed to the plugin
	 * @param   object   $article  A JTableContent object
	 * @param   boolean  $isNew    If the content has just been created
	 *
	 * @return  boolean  Allways true as this should not interupt the onAfterSave
	 *
	 * @since   1.0
	 */
	public function onContentAfterSave($context, $article, $isNew)
	{
		// Check this is a new article.
		if (!$isNew)
		{
			return true;
		}

		if (!in_array($context, $this->supportedContext))
		{
			return true;
		}

		// Check the context's & if the option is enabled
		if ($context === 'com_content.article' && $this->params->get('article_backend', 0) != 1)
		{
			return true;
		}
		
		if ($context === 'com_content.form' && $this->params->get('article_frontend', 0) != 1)
		{
			return true;
		}

		// Set article && mailtoUsergroupId for future usage
		$this->article           = $article; 
		$this->mailtoUsergroupId = $this->params->get('usergroup', false);
		$this->user              = Factory::getUser();

		$recipients = $this->getNewsletterRecipients();

		// Send the mails to the groups.
		if (isset($recipients['usergroup']))
		{
			$this->sendMailtoRecipients($recipients['usergroup']);
		}

		if (isset($recipients['custom']))
		{
			$this->sendMailtoRecipients($recipients['custom']);
		}

		if (isset($recipients['admin']))
		{
			$this->sendMailtoRecipients($recipients['admin']);
		}
	}

	/**
	 * This method retruns one array containing all email recipients
	 *
	 * @param   array    $recipients  The recipients of the messages
	 *
	 * @since   1.0
	 */
	private function sendMailtoRecipients($recipients)
	{
		// Send the emails to the Super Users
		foreach ($recipients as $recipient)
		{
			if (is_object($recipient))
			{
				$email  = $recipient->email;
				$userId = $recipient->id;
				$canUnsubscribe = true;

				// Set the receiver User
				$this->receiveruser = Factory::getUser($userId);
			}
			else
			{
				$email  = $recipient;
				$userId = false;
				$canUnsubscribe = false;
			}

			$emailSubject = $this->computeSubject(
				$this->params->get(
					'newslettersubject',
					Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_SUBJECT_NEWSLETTER_DEFAULT')
				)
			);

			$emailBody = $this->computeBody(
				$this->params->get(
					'newsletterbody',
					Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_BODY_NEWSLETTER_DEFAULT')
				)
			);

			// Overwirte the body with the other text if the user can't unsubscribe
			if (!$canUnsubscribe)
			{
				$emailBody = $this->computeBody(
					$this->params->get(
						'unabletounsubsribebody',
						Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_NO_UNSUBSCRIBE_POSSIBLE_DEFAULT')
					)
				);
			}

			$emailBody = $this->computeUnsubscribeLink($emailBody, $userId);

			$sent = $this->sendMail($emailSubject, $emailBody, $email);

			if (!$sent)
			{
				// Give the current user a message that the mail could not be send so he can contact the admin
				$this->app->enqueueMessage(Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_ERROR_MAIL_NOT_SEND'), $this->getRedirectType(false));
			}
		}

		return true;
	}

	/**
	 * This method retruns one array containing all email recipients
	 *
	 * @return  array  One array containing all email recipients
	 *
	 * @since   1.0
	 */
	private function getNewsletterRecipients()
	{
		$mailRecipients = array();

		// Catch email to users there have systememail enabled
		if ((int) $this->params->get('mailto_admins') === 1)
		{
			// Catch the emails from the DB
			$query = $this->db->getQuery(true)
				->select($this->db->quoteName('email'))
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('sendEmail') . " = " . $this->db->quote('1'));
			$this->db->setQuery($query);

			$mailRecipients['admin'] = (array) $this->db->loadColumn();
		}

		// Catch email to custom addresses
		if ((int) $this->params->get('mailto_custom') === 1)
		{
			$mailRecipients['custom'] = (array) explode(';', $this->params->get('custom_emails', ''));
		}

		// Catch email based on usergroup
		if ((int) $this->params->get('mailto_usergroup') === 1)
		{
			if (!$this->mailtoUsergroupId === false)
			{
				$mailtoUsergroupIdUsers = Access::getUsersByGroup($this->mailtoUsergroupId);

				if (is_array($mailtoUsergroupIdUsers))
				{
					$query = $this->db->getQuery(true)
						->select($this->db->quoteName(array('id' ,'email')))
						->from($this->db->quoteName('#__users'))
						->where($this->db->quoteName('id') . ' IN (' . implode(',', $mailtoUsergroupIdUsers) . ')')
						->where($this->db->quoteName('block') . ' = ' . $this->db->quote('0'));
					$this->db->setQuery($query);

					$mailRecipients['usergroup'] = $this->db->loadObjectList();
				}
			}
		}

		// retrun the mail recipients
		return $mailRecipients;
	}

	/**
	 * Compute the unsubsecibe link
	 *
	 * @param   string   $body    The subject to compute
	 * @param   integer  $userId  The subject to compute
	 *
	 * @return  string  Return bopy with replaced unsubscribe link
	 *
	 * @since   1.0
	 */
	private function computeUnsubscribeLink($body, $userId)
	{
	
		$frontendUrl = str_replace('administrator/', '', Uri::base());
		$token       = $this->getUserProfileValue($userId);

		if ($token === false)
		{
			$token = JApplicationHelper::getHash(JUserHelper::genRandomPassword());
			$this->setUserProfileValue($userId, $token);
		}

		$unsubscribeUrl = $frontendUrl . '?unsubscribe=1&userid=' . $userId . '&token=' . $token;

		return str_replace('[UNSUBSCRIBE-URL]', $unsubscribeUrl, $body);
	}

	/**
	 * Returns if there is a token set for the current user 
	 *
	 * @param   $userId  The userid you want to check on the database
	 *
	 * @return  mixed  The value if the key exists. False in any other case.
	 *
	 * @since   1.0
	 */
	private function getUserProfileValue($userId)
	{
		try
		{
			$query = $this->db->getQuery(true)
				->select($this->db->qn('profile_value'))
				->from($this->db->qn('#__user_profiles'))
				->where($this->db->qn('user_id') . ' = ' . $userId)
				->where($this->db->qn('profile_key') . ' = ' . $this->db->q('plg_system_newsletterlight_token'));
			$this->db->setQuery($query);

			$userProfileValue = $this->db->loadResult();
		}
		catch (\Exception $e)
		{
			// We can't do much about is at this place
			return false;
		}

		if (!empty($userProfileValue))
		{
			return $userProfileValue;
		}

		return false;
	}

	/**
	 * Sets the Profile value for the given user to the given value 
	 *
	 * @param   $userId  The userid you want to check on the database
	 *
	 * @return  boolean  True on sucess or if the value already exists. False in any other case.
	 *
	 * @since   1.0
	 */
	private function setUserProfileValue($userId, $value)
	{
		try
		{
			$query = $this->db->getQuery(true)
				->insert($this->db->qn('#__user_profiles'))
				->columns(array($this->db->qn('user_id'), $this->db->qn('profile_key'), $this->db->qn('profile_value'), $this->db->qn('ordering')))
				->values(
						$this->db->q($userId) . ' , ' .
						$this->db->q('plg_system_newsletterlight_token') . ' , ' .
						$this->db->q($value) . ' , ' .
						$this->db->q(0)
				);
			$this->db->setQuery($query);
			$this->db->execute();
		}
		catch (\Exception $e)
		{
			// We can't do much about is at this place
			return false;
		}

		return true;
	}

	/**
	 * Compute the newsletter subject
	 *
	 * @param   string  $subject  The subject to compute
	 *
	 * @return  string  Return the newsletter subject
	 *
	 * @since   1.0
	 */
	private function computeSubject($subject)
	{
		$messagePlaceholders = array(
			'[URL]'      => $this->currentUri->toString(array('host', 'port')),
		);

		// Check the receiver user
		if (isset($this->user))
		{
			$messagePlaceholders['[USERNAME]'] = $this->user->username;
			$messagePlaceholders['[NAME]']     = $this->user->name;
		}

		// Check the receiver user
		if (isset($this->receiveruser))
		{
			$messagePlaceholders['[RECEIVER-USERNAME]'] = $this->receiveruser->username;
			$messagePlaceholders['[RECEIVER-NAME]']     = $this->receiveruser->name;
		}

		if (isset($this->article->title))
		{
			$messagePlaceholders['[TITLE]'] = $this->article->title;
		}

		foreach ($messagePlaceholders as $key => $value)
		{
			$subject = str_replace($key, $value, $subject);
		}

		return $subject;
	}

	/**
	 * Compute the newsletter subbodyject
	 *
	 * @param   string  $body  The body to compute
	 *
	 * @return  string  Return the newsletter subject
	 *
	 * @since   1.0
	 */
	private function computeBody($body)
	{
		// Set the default placeholders
		$messagePlaceholders = array(
			'[USERNAME]' => $this->user->username,
			'[NAME]'     => $this->user->name,
			'[URL]'      => $this->currentUri->toString(array('host', 'port')),
			'\\n'        => '<br>',
			"\n"         => '<br>',
		);

		// Check the receiver user
		if (isset($this->receiveruser))
		{
			$messagePlaceholders['[RECEIVER-USERNAME]'] = $this->receiveruser->username;
			$messagePlaceholders['[RECEIVER-NAME]']     = $this->receiveruser->name;
		}

		// Only if we have the article object we can do something with it ;)
		if (isset($this->article))
		{
			$category = $this->db->getQuery(true)
				->select($this->db->quoteName('title'))
				->from($this->db->quoteName('#__categories'))
				->where($this->db->quoteName('id') . " = " . $this->db->quote($this->article->catid));
			$this->db->setQuery($category);

			$categoryName = $this->db->loadResult();
			$introtext    = str_replace('<br>', '<br />', $this->article->introtext);
			$fulltext     = str_replace('<br>', '<br />', $this->article->fulltext);

			// Define article introtext and fulltext
			if ($fulltext === '')
			{
				$fulltext = $introtext;
			}
			else
			{
				$fulltext = $introtext . '<br>' . $fulltext;
			}

			$frontendUrl = str_replace('administrator/', '', Uri::base());

			// Add additonal Message Placeholders
			$messagePlaceholders['[CATEGORY]']  = $categoryName;
			$messagePlaceholders['[TITLE]']     = $this->article->title;
			$messagePlaceholders['[INTROTEXT]'] = $introtext;
			$messagePlaceholders['[FULLTEXT]']  = $fulltext;
			$messagePlaceholders['[LINK]']      = $frontendUrl . 'index.php?option=com_content&view=article&id=' . $this->article->id;
		}

		foreach ($messagePlaceholders as $key => $value)
		{
			$body = str_replace($key, $value, $body);
		}

		return $body;
	}

	/**
	 * Clean up the current uri from the parameters used to call this feature.
	 *
	 * @return  string  The raw uri without the parameters we use
	 *
	 * @since   1.0
	 */
	private function getReturnUri()
	{
		// Remove the url parameters we use so we redirect the user to back to the origin site.
		$this->currentUri->delVar('unsubscribe');
		$this->currentUri->delVar('userid');
		$this->currentUri->delVar('token');

		return (string) $this->currentUri;
	}

	/**
	 * Return the correct message type for $app->enqueueMessage for the given last check result
	 *
	 * @param   boolean  $lastCheckStatus  The last returned status code tru if successfull or false if not.
	 *
	 * @return  string   The correct message type for $app->enqueueMessage
	 *
	 * @since   1.0
	 */
	private function getRedirectType($lastCheckStatus)
	{
		if ($lastCheckStatus)
		{
			return 'message';
		}

		return 'error';
	}

	/**
	 * Send the Mail
	 *
	 * @param   string   $subject  The email subject
	 * @param   string   $body     The email body
	 * @param   string   $email    The email the message should be send to
	 * @param   boolean  $ishtml   If true the mail is set in html mode
	 *
	 * @return  boolean  True if the mail send out succesfull else return false
	 *
	 * @since   1.0
	 */
	private function sendMail($emailSubject, $emailBody, $email)
	{
		// Replace merge codes with their values
		$mailFrom = Factory::getConfig()->get('mailfrom');
		$fromName = Factory::getConfig()->get('fromname');

		try
		{
			$mailer = Factory::getMailer();
			$mailer->isHtml(true);
			$mailer->setSender(array($mailFrom, $fromName));
			$mailer->addRecipient($email);
			$mailer->setSubject($emailSubject);
			$mailer->setBody($emailBody);
			$result = $mailer->Send();
		}
		catch (Exception $e)
		{
			return false;
		}

		return $result;
	}
}
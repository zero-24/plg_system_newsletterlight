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
use Joomla\CMS\Router\Router;

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

		// Get the user object
		$this->user = Factory::getUser();

		// Set some options we need later
		$this->mailtoUsergroupId = $this->params->get('usergroup', false);

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

		// Unauthenticated users can't use this
		if ($this->user->guest)
		{
			return;
		}

		// Only if unsubscribe && userID matches the unsubscribe should be done.
		if ($unsubscribe === 1 && $userId === $this->user->id)
		{
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
	 * @param   integer  $userId The userId to assign the new group
	 *
	 * @return  boolean  True if the mail was send
	 *
	 * @since   1.0
	 */
	private function sendMailUserUnsubscribed($userId)
	{
		// Send Mail to the user that unsubscribed
		$unsubscribedSubjectUser = $this->getUnsubsribedSubject();
		$unsubscribedBodyUser    = $this->getUnsubsribedBody();

		$sent = $this->sendMail($unsubscribedSubjectUser, $unsubscribedBodyUser, Factory::getUser($userId)->email);

		if (!$sent)
		{
			// Give the current user a message that the mail could not be send so he can contact the admin
			$this->friendlyMessage = Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_ERROR_MAIL_NOT_SEND');

			return false;
		}

		// Send Mail to the unsubscribe admin mail
		if ((int) $this->params->get('mail_unsubscribe') === 0)
		{
			return true;
		}

		$unsubscribeAdminEmails   = (array) explode(';', $this->params->get('unsubscribe_emails', array()));
		$unsubscribedSubjectAdmin = $this->getUnsubsribedAdminSubject();
		$unsubscribedBodyAdmin    = $this->getUnsubsribedAdminBody();

		// Send the emails to the Super Users
		foreach ($unsubscribeAdminEmails as $recipient)
		{
			$sent = $this->sendMail($emailSubject, $emailBody, $recipient);

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

		// Set article for future usage
		$this->article = $article;

		$emailSubject = $this->getNewsletterSubject();
		$emailBody    = $this->getNewsletterBody();
		$recipients   = $this->getNewsletterRecipients();

		// Send the emails to the Super Users
		foreach ($recipients as $recipient)
		{
			$sent = $this->sendMail($emailSubject, $emailBody, $recipient);

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
		$adminMails   = arrray();
		$customEmails = arrray();
		$groupEmails  = arrray();

		// Catch email to users there have systememail enabled
		if ((int) $this->params->get('mailto_admins') === 1)
		{
			// Catch the emails from the DB
			$query = $this->db->getQuery(true)
				->select($this->db->quoteName('email'))
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('sendEmail') . " = " . 1);
			$this->db->setQuery($query);

			$adminMails = (array) $this->db->loadColumn();
		}

		// Catch email to custom addresses
		if ((int) $this->params->get('mailto_custom') === 1)
		{
			$customEmails = (array) explode(';', $this->params->get('custom_emails', ''));
		}

		// Catch email based on usergroup
		if ((int) $this->params->get('mailto_usergroup') === 1)
		{
			if (!$this->mailtoUsergroupId === false)
			{
				$groupEmails = Access::getUsersByGroup($this->mailtoUsergroupId);
			}
		}

		// Merge all the mails & retrun the unique mails
		return array_unique(array_merge($adminMails, $customEmails, $groupEmails));
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
			'[TITLE]' => $this->article->title,
			'[URL]'   => Uri::base(),
		);

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

		$messagePlaceholders = array(
			'[USERNAME]'        => $this->user->get('username'),
			'[CATEGORY]'        => $categoryName,
			'[TITLE]'           => $this->article->title,
			'[INTROTEXT]'       => $introtext,
			'[FULLTEXT]'        => $fulltext,
			'[LINK]'            => Uri::root() . Route::_('index.php?option=com_content&view=article&id=' . $this->article->id),
			'[URL]'             => Uri::base(),
			'[UNSUBSCRIBE-URL]' => Uri::base() . '?unsubscribe=1&userid=' . $this->user->id,
			'\\n'               => "\n",
		);

		foreach ($messagePlaceholders as $key => $value)
		{
			$body = str_replace($key, $value, $body);
		}

		return $body;
	}

	/**
	 * Get the newsletter subject
	 *
	 * @return  string  Return the newsletter subject
	 *
	 * @since   1.0
	 */
	private function getNewsletterSubject()
	{
		return $this->computeSubject($this->params->get('newslettersubject', Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_SUBJECT_NEWSLETTER_DEFAULT')));
	}

	/**
	 * Get the newsletter body
	 *
	 * @return  string  Return the newsletter body
	 *
	 * @since   1.0
	 */
	private function getNewsletterBody()
	{
		return $this->computeBody($this->params->get('newsletterbody', Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_BODY_NEWSLETTER_DEFAULT')));
	}

	/**
	 * Get the Unsubsribed subject
	 *
	 * @return  string  Return the Unsubsribed subject
	 *
	 * @since   1.0
	 */
	private function getUnsubsribedSubject()
	{
		return $this->computeSubject($this->params->get('unsubsribedsubject', Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_SUBJECT_UNSUBSRCRIBED_DEFAULT')));
	}

	/**
	 * Get the Unsubsribed body
	 *
	 * @return  string  Return the Unsubsribed body
	 *
	 * @since   1.0
	 */
	private function getUnsubsribedBody()
	{
		return $this->computeBody($this->params->get('unsubsribedbody', Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_BODY_UNSUBSRCRIBED_DEFAULT')));
	}

	/**
	 * Get the Unsubsribed subject
	 *
	 * @return  string  Return the Unsubsribed subject
	 *
	 * @since   1.0
	 */
	private function getUnsubsribedAdminSubject()
	{
		return $this->computeSubject($this->params->get('unsubsribedadminsubject', Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_SUBJECT_UNSUBSRCRIBED_ADMIN_DEFAULT')));
	}

	/**
	 * Get the Unsubsribed body
	 *
	 * @return  string  Return the Unsubsribed body
	 *
	 * @since   1.0
	 */
	private function getUnsubsribedAdminBody()
	{
		return $this->computeBody($this->params->get('unsubsribedadminbody', Text::_('PLG_SYSTEM_NEWSLETTERLIGHT_BODY_UNSUBSRCRIBED_ADMIN_DEFAULT')));
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
		// Remove all url parameters we use so we redirect the user to back to the origin site.
		$currentUri = Uri::getInstance();
		$currentUri->delVar('unsubscribe');
		$currentUri->delVar('userid');

		return (string) $currentUri;
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
	 * @param   string  $subject  The email subject
	 * @param   string  $body     The email body
	 * @param   string  $email    The email the message should be send to
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
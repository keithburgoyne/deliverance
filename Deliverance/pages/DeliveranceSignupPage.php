<?php

require_once 'Swat/SwatMessage.php';
require_once 'Site/pages/SiteEditPage.php';

/**
 * @package   Deliverance
 * @copyright 2009-2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
abstract class DeliveranceSignupPage extends SiteEditPage
{
	// {{{ protected properties

	protected $send_welcome = true;

	// }}}
	// {{{ protected function getUiXml()

	protected function getUiXml()
	{
		return 'Deliverance/pages/signup.xml';
	}

	// }}}

	// process phase
	// {{{ protected function save()

	protected function save(SwatForm $form)
	{
		$list = $this->getList();
		$this->subscribe($list);
	}

	// }}}
	// {{{ abstract protected function getList()

	abstract protected function getList();

	// }}}
	// {{{ protected function subscribe()

	protected function subscribe(DeliveranceList $list)
	{
		$email     = $this->getEmail();
		$info      = $this->getSubscriberInfo();
		$array_map = $this->getArrayMap();

		$this->checkMember($list, $email);

		$response = $list->subscribe($email, $info, $this->send_welcome,
			$array_map);

		$message = $list->handleSubscribeResponse($response);
		if ($message instanceof SwatMessage) {
			$this->ui->getWidget('message_display')->add($message);
		}
	}

	// }}}
	// {{{ protected function getEmail()

	protected function getEmail()
	{
		return $this->ui->getWidget('email')->value;
	}

	// }}}
	// {{{ abstract protected function getSubscriberInfo();

	abstract protected function getSubscriberInfo();

	// }}}
	// {{{ protected function getArrayMap()

	protected function getArrayMap()
	{
		return array();
	}

	// }}}
	// {{{ protected function checkMember()

	protected function checkMember(DeliveranceList $list, $email)
	{
		if ($list->isMember($email)) {
			$this->send_welcome = false;
			$message = $this->getExistingMemberMessage($list, $email);
			if ($message != null) {
				$this->app->messages->add($message);
			}
		}
	}

	// }}}
	// {{{ protected function getExistingMemberMessage()

	protected function getExistingMemberMessage(DeliveranceList $list, $email)
	{
		// TODO: rewrite.
		$message = new SwatMessage(
			Deliverance::_(
				'Thank you. Your email address was already subscribed to '.
				'our newsletter.'
			),
			'notice'
		);

		$message->secondary_content = Deliverance::_(
			'Your subscriber information has been updated, and you will '.
			'continue to receive mailings at this address.'
		);

		return $message;
	}

	// }}}
	// {{{ protected function relocate()

	protected function relocate(SwatForm $form)
	{
		if ($this->ui->getWidget('message_display')->getMessageCount() == 0) {
			$this->app->relocate($this->source.'/thankyou');
		}
	}

	// }}}
}

?>
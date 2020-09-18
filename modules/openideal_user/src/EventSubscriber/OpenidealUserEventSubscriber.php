<?php

namespace Drupal\openideal_user\EventSubscriber;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Session\AccountProxy;
use Drupal\ckeditor_mentions\CKEditorMentionEvent;
use Drupal\comment\Entity\Comment;
use Drupal\content_moderation\Event\ContentModerationEvents;
use Drupal\content_moderation\Event\ContentModerationStateChangedEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\openideal_user\Event\OpenidealContentModerationEvent;
use Drupal\openideal_user\Event\OpenidealUserEvents;
use Drupal\openideal_user\Event\OpenidealUserMentionEvent;
use Drupal\user\UserDataInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class OpenidealUserEventSubscriber.
 */
class OpenidealUserEventSubscriber implements EventSubscriberInterface {

  /**
   * Tour related information.
   */
  const FRONT_PAGE_TOUR_COOKIE = 'OpenideaL_visitor_tour_front_page';
  const USER_DATA_TOUR_NAME = 'front_page_tour';

  /**
   * Event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * User data.
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected $userData;

  /**
   * Time.
   *
   * @var \Drupal\Component\Datetime\Time
   */
  protected $time;

  /**
   * OpenidealSocialAuthSubscriber constructor.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Component\Datetime\Time $time
   *   Time.
   * @param \Drupal\Core\Session\AccountProxy $currentUser
   *   Current user.
   * @param \Drupal\user\UserDataInterface $userData
   *   User data.
   */
  public function __construct(
    EventDispatcherInterface $event_dispatcher,
    EntityTypeManagerInterface $entity_type_manager,
    Time $time,
    AccountProxy $currentUser,
    UserDataInterface $userData
  ) {
    $this->eventDispatcher = $event_dispatcher;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $currentUser;
    $this->userData = $userData;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      CKEditorMentionEvent::MENTION_FIRST => 'usersAreMentioned',
      ContentModerationEvents::STATE_CHANGED => 'stateChanged',
      KernelEvents::RESPONSE => 'response',
    ];
  }

  /**
   * This method is called when the RESPONSE event is dispatched.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   Dispatched event.
   */
  public function response(FilterResponseEvent $event) {
    if ($event->getRequest()->get('_route') == 'view.frontpage.front_page') {
      // Set a cookie for anonymous user when
      // visits front page for the first time.
      if ($this->currentUser->isAnonymous() && !$event->getRequest()->cookies->has('OpenideaL_visitor_tour_front_page')) {
        $cookie = new Cookie('OpenideaL.visitor.tour_front_page', '1', $this->time->getRequestTime() + 31536000, '/');
        $response = $event->getResponse();
        $response->headers->setCookie($cookie);
      }
      // Same with authenticated user, set indicator into user.data
      // service that user has already visited frontpage.
      elseif (!$this->currentUser->isAnonymous() && !$this->userData->get('openideal_user', $this->currentUser->id(), self::FRONT_PAGE_TOUR_COOKIE)) {
        $this->userData->set('openideal_user', $this->currentUser->id(), self::USER_DATA_TOUR_NAME, '1');
      }
    }
  }

  /**
   * This method is called when the MENTION_FIRST event is dispatched.
   *
   * @param \Drupal\ckeditor_mentions\CKEditorMentionEvent $event
   *   The dispatched event.
   */
  public function usersAreMentioned(CKEditorMentionEvent $event) {
    if ((($comment = $event->getEntity()) instanceof Comment) && !empty($event->getMentionedUsers())) {
      // If user was mentioned twice in comment remove it.
      $user_ids = array_unique(array_keys($event->getMentionedUsers()));
      foreach ($user_ids as $id) {
        $storage = $this->entityTypeManager->getStorage('user');
        $user = $storage->load($id);
        $event = new OpenidealUserMentionEvent($comment, $user);
        $this->eventDispatcher->dispatch(OpenidealUserEvents::OPENIDEAL_USER_MENTION, $event);
      }
    }
  }

  /**
   * This method is called when the MENTION_FIRST event is dispatched.
   *
   * @param \Drupal\content_moderation\Event\ContentModerationStateChangedEvent $event
   *   The dispatched event.
   */
  public function stateChanged(ContentModerationStateChangedEvent $event) {
    $event = new OpenidealContentModerationEvent($event);
    $this->eventDispatcher->dispatch(OpenidealUserEvents::WORKFLOW_STATE_CHANGED, $event);
  }

}

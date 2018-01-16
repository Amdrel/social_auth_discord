<?php

namespace Drupal\social_auth_discord\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\social_api\Plugin\NetworkManager;
use Drupal\social_auth\SocialAuthDataHandler;
use Drupal\social_auth\SocialAuthUserManager;
use Drupal\social_auth_discord\DiscordAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Returns responses for Social Auth Reddit module routes.
 */
class DiscordAuthController extends ControllerBase {

  /**
   * The network plugin manager.
   *
   * @var \Drupal\social_api\Plugin\NetworkManager
   */
  private $networkManager;

  /**
   * The user manager.
   *
   * @var \Drupal\social_auth\SocialAuthUserManager
   */
  private $userManager;

  /**
   * The discord authentication manager.
   *
   * @var \Drupal\social_auth_discord\DiscordAuthManager
   */
  private $discordManager;

  /**
   * Used to access GET parameters.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $request;

  /**
   * The Social Auth Data Handler.
   *
   * @var \Drupal\social_auth\SocialAuthDataHandler
   */
  private $dataHandler;

  /**
   * DiscordAuthController constructor.
   *
   * @param \Drupal\social_api\Plugin\NetworkManager $network_manager
   *   Used to get an instance of social_auth_discord network plugin.
   * @param \Drupal\social_auth\SocialAuthUserManager $user_manager
   *   Manages user login/registration.
   * @param \Drupal\social_auth_discord\DiscordAuthManager $discord_manager
   *   Used to manage authentication methods.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   Used to access GET parameters.
   * @param \Drupal\social_auth\SocialAuthDataHandler $data_handler
   *   SocialAuthDataHandler object.
   */
  public function __construct(NetworkManager $network_manager,
                              SocialAuthUserManager $user_manager,
                              DiscordAuthManager $discord_manager,
                              RequestStack $request,
                              SocialAuthDataHandler $data_handler) {

    $this->networkManager = $network_manager;
    $this->userManager = $user_manager;
    $this->discordManager = $discord_manager;
    $this->request = $request;
    $this->dataHandler = $data_handler;

    // Sets the plugin id.
    $this->userManager->setPluginId('social_auth_discord');

    // Sets the session keys to nullify if user could not logged in.
    $this->userManager->setSessionKeysToNullify(['access_token', 'oauth2state']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.network.manager'),
      $container->get('social_auth.user_manager'),
      $container->get('social_auth_discord.manager'),
      $container->get('request_stack'),
      $container->get('social_auth.data_handler')
    );
  }

  /**
   * Response for path 'user/login/discord'.
   *
   * Redirects the user to Discord for authentication.
   */
  public function redirectToDiscord() {
    /* @var Wohali\OAuth2\Client\Provider\Discord false $discord */
    $discord = $this->networkManager->createInstance('social_auth_discord')->getSdk();

    // If discord client could not be obtained.
    if (!$discord) {
      drupal_set_message($this->t('Social Auth Discord not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Destination parameter specified in url.
    $destination = $this->request->getCurrentRequest()->get('destination');
    // If destination parameter is set, save it.
    if ($destination) {
      $this->userManager->setDestination($destination);
    }

    // Discord service was returned, inject it to $discordManager.
    $this->discordManager->setClient($discord);

    // Generates the URL where the user will be redirected for Discord login.
    // If the user did not have email permission granted on previous attempt,
    // we use the re-request URL requesting only the email address.
    $discord_login_url = $this->discordManager->getDiscordLoginUrl();

    $state = $this->discordManager->getState();

    $this->dataHandler->set('oauth2state', $state);

    return new TrustedRedirectResponse($discord_login_url);
  }

  /**
   * Response for path 'user/login/discord/callback'.
   *
   * Discord returns the user here after user has authenticated in Discord.
   */
  public function callback() {
    // Checks if user cancel login via Discord.
    $error = $this->request->getCurrentRequest()->get('error');
    if ($error == 'access_denied') {
      drupal_set_message($this->t('You could not be authenticated.'), 'error');
      return $this->redirect('user.login');
    }

    /* @var Wohali\OAuth2\Client\Provider\Discord|false $discord */
    $discord = $this->networkManager->createInstance('social_auth_discord')->getSdk();

    // If Discord client could not be obtained.
    if (!$discord) {
      drupal_set_message($this->t('Social Auth Discord not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    $state = $this->dataHandler->get('oauth2state');

    // Retrieves $_GET['state'].
    $retrievedState = $this->request->getCurrentRequest()->query->get('state');
    if (empty($retrievedState) || ($retrievedState !== $state)) {
      $this->userManager->nullifySessionKeys();
      drupal_set_message($this->t('Discord login failed. Unvalid OAuth2 state.'), 'error');
      return $this->redirect('user.login');
    }

    // Saves access token to session.
    $this->dataHandler->set('access_token', $this->discordManager->getAccessToken());

    $this->discordManager->setClient($discord)->authenticate();

    // Gets user's info from Discord API.
    if (!$discord_profile = $this->discordManager->getUserInfo()) {
      drupal_set_message($this->t('Discord login failed, could not load Discord profile. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Store the data mapped with data points define is
    // social_auth_discord settings.
    $data = [];

    if (!$this->userManager->checkIfUserExists($discord_profile->getId())) {
      $api_calls = explode(PHP_EOL, $this->discordManager->getApiCalls());

      // Iterate through api calls define in settings and try to retrieve them.
      foreach ($api_calls as $api_call) {

        $call = $this->discordManager->getExtraDetails($api_call);
        array_push($data, $call);
      }
    }
    // If user information could be retrieved.
    return $this->userManager->authenticateUser($discord_profile->getName(), $discord_profile->getEmail(), $discord_profile->getId(), $this->discordManager->getAccessToken(), $discord_profile->getAvatarHash(), json_encode($data));
  }

}

<?php

namespace Drupal\social_auth_discord\Settings;

/**
 * Defines an interface for Social Auth Discord settings.
 */
interface DiscordAuthSettingsInterface {

  /**
   * Gets the client ID.
   *
   * @return string
   *   The client ID.
   */
  public function getClientId();

  /**
   * Gets the client secret.
   *
   * @return string
   *   The client secret.
   */
  public function getClientSecret();

}

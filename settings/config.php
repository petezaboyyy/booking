<?php
/* config.php */
return array (
  'version' => '7.0.3',
  'web_title' => 'E-Booking',
  'web_description' => 'ระบบจองห้องประชุม',
  'timezone' => 'Asia/Bangkok',
  'password_key' => '6a61e2700f289',
  'reversion' => 1789464553,
  'api_tokens' => 
  array (
    'internal' => '2509ff5b61aa488e68e479248ac3371ec3d890b4',
    'external' => 'ae8aeadd664c89c1fd5c91cfb3f5cdae15692df8',
  ),
  'api_secret' => '93cda3ffd346d',
  'jwt_secret' => '438844fc3d07ef6a6955669fb32990bde2c1532288440fdbf5dac5e2980778cd',
  'api_ips' => 
  array (
    0 => '0.0.0.0',
  ),
  'api_cors' => '*',
  'booking_approving' => 2,
  'booking_cancellation' => 4,
  'booking_delete' => 
  array (
    0 => 0,
    2 => 2,
    3 => 3,
    4 => 4,
  ),
  'booking_approve_level' => 1,
  'booking_approve_status' => 
  array (
    1 => 1,
  ),
  'booking_approve_department' => 
  array (
    1 => '',
  ),
  'activate_user' => 0,
  'demo_mode' => 0,
  'new_members_active' => 1,
  'require_terms_acceptance' => 1,
  'user_forgot' => 1,
  'user_register' => 1,
  'cache_expire' => 5,
  'max_login_attempts' => 5,
  'lockout_duration' => 30,
  'facebook_appId' => '',
  'default_department' => '',
  'google_client_id' => '',
  'pusher_app_id' => getenv('PUSHER_APP_ID') ?: '',
  'pusher_key' => getenv('PUSHER_KEY') ?: '',
  'pusher_secret' => getenv('PUSHER_SECRET') ?: '',
  'pusher_cluster' => getenv('PUSHER_CLUSTER') ?: 'ap1',
  'microsoft_client_id' => 'b8b1c97a-ac2c-4279-bd09-2906ea3d368e',
  'microsoft_tenant' => 'cf81f1df-de59-4c29-91da-a2dfd04aa751',
  'microsoft_allowed_domains' => 'cmu.ac.th',
  'microsoft_redirect_uri' => 'http://localhost:8080/microsoft/callback.php',
  'microsoft_client_secret' => getenv('MICROSOFT_CLIENT_SECRET') ?: '',
);

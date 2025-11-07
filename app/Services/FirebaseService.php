<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        try {
            $factory = (new Factory)->withServiceAccount(storage_path(env('FIREBASE_CREDENTIALS')));
            $this->messaging = $factory->createMessaging();
            // Log::info('✅ Firebase Messaging initialized');
        } catch (\Exception $e) {
            // Log::error('❌ Firebase initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send notification to single device
     */
    public function sendToDevice(string $token, string $title, string $body, array $data = [], string $deviceType = 'android')
    {
        try {
            // Log::info('Sending notification to device', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'title' => $title,
            //     'device_type' => $deviceType,
            //     'data_type' => $data['type'] ?? 'unknown'
            // ]);

            // ✅ Create notification
            $notification = Notification::create($title, $body);

            // ✅ Build message with proper Android config (FCM v1 compatible)
            $message = CloudMessage::withTarget('token', $token)
                ->withNotification($notification)
                ->withData($data);

            // ✅ FIX: Use proper Android config without deprecated fields
            if ($deviceType === 'android') {
                $message = $message->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high', // ✅ This is still valid at Android config level
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel',
                            'notification_priority' => 'PRIORITY_HIGH', // ✅ Use notification_priority instead
                            'default_sound' => true,
                            'default_vibrate_timings' => true,
                        ],
                    ])
                );
            } else {
                // iOS config
                $message = $message->withApnsConfig(
                    ApnsConfig::fromArray([
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'alert' => [
                                    'title' => $title,
                                    'body' => $body,
                                ],
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ])
                );
            }

            // Send message
            $result = $this->messaging->send($message);

            // Log::info('✅ Notification sent successfully', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'title' => $title
            // ]);

            return [
                'success' => true,
                'message_id' => $result
            ];

        } catch (\Kreait\Firebase\Exception\Messaging\InvalidMessage $e) {
            // Log::error('❌ Invalid FCM message', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'error' => $e->getMessage(),
            //     'errors' => $e->errors()
            // ]);

            return [
                'success' => false,
                'error' => 'Invalid message: ' . $e->getMessage()
            ];

        } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
            // Log::warning('⚠️ Token not found (probably unregistered)', [
            //     'token' => substr($token, 0, 20) . '...'
            // ]);

            return [
                'success' => false,
                'error' => 'Token not found',
                'should_delete_token' => true
            ];

        } catch (\Exception $e) {
            // Log::error('❌ FCM sending failed', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send notification to multiple devices
     */
    public function sendToMultipleDevices(array $tokens, string $title, string $body, array $data = [], string $deviceType = 'android')
    {
        if (empty($tokens)) {
            // Log::warning('⚠️ No tokens provided for multicast');
            return [
                'success' => false,
                'error' => 'No tokens provided'
            ];
        }

        try {
            // Log::info('Sending multicast notification', [
            //     'tokens_count' => count($tokens),
            //     'title' => $title,
            //     'device_type' => $deviceType
            // ]);

            // ✅ Create notification
            $notification = Notification::create($title, $body);

            // ✅ Build message with proper config
            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData($data);

            // ✅ Add platform-specific config
            if ($deviceType === 'android') {
                $message = $message->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel',
                            'notification_priority' => 'PRIORITY_HIGH',
                            'default_sound' => true,
                            'default_vibrate_timings' => true,
                        ],
                    ])
                );
            } else {
                $message = $message->withApnsConfig(
                    ApnsConfig::fromArray([
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'alert' => [
                                    'title' => $title,
                                    'body' => $body,
                                ],
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ])
                );
            }

            // Send multicast
            $report = $this->messaging->sendMulticast($message, $tokens);

            $successCount = $report->successes()->count();
            $failureCount = $report->failures()->count();

            // Log::info('✅ Multicast notification completed', [
            //     'success_count' => $successCount,
            //     'failure_count' => $failureCount,
            //     'total' => count($tokens)
            // ]);

            // Log failures
            if ($failureCount > 0) {
                foreach ($report->failures()->getItems() as $failure) {
                    $failedToken = $failure->target()->value();
                    $error = $failure->error()->getMessage();
                    
                    // Log::warning('⚠️ Notification failed for token', [
                    //     'token' => substr($failedToken, 0, 20) . '...',
                    //     'error' => $error
                    // ]);
                }
            }

            return [
                'success' => true,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'total' => count($tokens)
            ];

        } catch (\Exception $e) {
            // Log::error('❌ Multicast failed', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ✅ ADDED: Send notification to karyawan (by karyawan_id)
     */
    public function sendToKaryawan(int $karyawanId, string $title, string $body, array $data = [])
    {
        try {
            // Get all active tokens for this karyawan
            $tokens = \App\Models\FcmToken::where('karyawan_id', $karyawanId)
                ->where('is_active', true)
                ->pluck('token')
                ->toArray();

            if (empty($tokens)) {
                // Log::warning('⚠️ No active tokens found for karyawan', [
                //     'karyawan_id' => $karyawanId
                // ]);

                return [
                    'success' => false,
                    'error' => 'No active tokens found'
                ];
            }

            // Log::info('Sending notification to karyawan', [
            //     'karyawan_id' => $karyawanId,
            //     'tokens_count' => count($tokens),
            //     'title' => $title,
            //     'data_type' => $data['type'] ?? 'unknown'
            // ]);

            // Get device type from first token (assume all tokens from same karyawan have same device type)
            $firstToken = \App\Models\FcmToken::where('karyawan_id', $karyawanId)
                ->where('is_active', true)
                ->first();

            $deviceType = $firstToken ? $firstToken->device_type : 'android';

            // Log::info('Device type detected', [
            //     'device_type' => $deviceType,
            //     'from_db' => $firstToken ? $firstToken->device_type : 'N/A'
            // ]);

            // If only one token, use sendToDevice
            if (count($tokens) === 1) {
                return $this->sendToDevice($tokens[0], $title, $body, $data, $deviceType);
            }

            // Multiple tokens, use multicast
            return $this->sendToMultipleDevices($tokens, $title, $body, $data, $deviceType);

        } catch (\Exception $e) {
            // Log::error('❌ Send to karyawan failed', [
            //     'karyawan_id' => $karyawanId,
            //     'error' => $e->getMessage()
            // ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ✅ ADDED: Send notification to admin user (by user_id)
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = [])
    {
        try {
            // Get all active tokens for this user
            $tokens = \App\Models\FcmToken::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('token')
                ->toArray();

            if (empty($tokens)) {
                // Log::warning('⚠️ No active tokens found for user', [
                //     'user_id' => $userId
                // ]);

                return [
                    'success' => false,
                    'error' => 'No active tokens found'
                ];
            }

            // Log::info('Sending notification to user', [
            //     'user_id' => $userId,
            //     'tokens_count' => count($tokens),
            //     'title' => $title
            // ]);

            // Get device type from first token
            $firstToken = \App\Models\FcmToken::where('user_id', $userId)
                ->where('is_active', true)
                ->first();

            $deviceType = $firstToken ? $firstToken->device_type : 'web';

            // If only one token, use sendToDevice
            if (count($tokens) === 1) {
                return $this->sendToDevice($tokens[0], $title, $body, $data, $deviceType);
            }

            // Multiple tokens, use multicast
            return $this->sendToMultipleDevices($tokens, $title, $body, $data, $deviceType);

        } catch (\Exception $e) {
            // Log::error('❌ Send to user failed', [
            //     'user_id' => $userId,
            //     'error' => $e->getMessage()
            // ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send to topic
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = [])
    {
        try {
            // Log::info('Sending notification to topic', [
            //     'topic' => $topic,
            //     'title' => $title
            // ]);

            $notification = Notification::create($title, $body);

            $message = CloudMessage::withTarget('topic', $topic)
                ->withNotification($notification)
                ->withData($data)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel',
                            'notification_priority' => 'PRIORITY_HIGH',
                        ],
                    ])
                );

            $result = $this->messaging->send($message);

            // Log::info('✅ Topic notification sent', [
            //     'topic' => $topic,
            //     'message_id' => $result
            // ]);

            return [
                'success' => true,
                'message_id' => $result
            ];

        } catch (\Exception $e) {
            // Log::error('❌ Topic notification failed', [
            //     'topic' => $topic,
            //     'error' => $e->getMessage()
            // ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate token
     */
    public function validateToken(string $token): bool
    {
        try {
            // Try to send a data-only message (won't show notification)
            $message = CloudMessage::withTarget('token', $token)
                ->withData(['validation' => 'test']);

            $this->messaging->validate($message);
            return true;

        } catch (\Exception $e) {
            // Log::warning('Token validation failed', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'error' => $e->getMessage()
            // ]);
            return false;
        }
    }

    /**
     * Subscribe to topic
     */
    public function subscribeToTopic(string $token, string $topic)
    {
        try {
            $this->messaging->subscribeToTopic($topic, [$token]);
            
            // Log::info('✅ Subscribed to topic', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'topic' => $topic
            // ]);

            return ['success' => true];

        } catch (\Exception $e) {
            // Log::error('❌ Topic subscription failed', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'topic' => $topic,
            //     'error' => $e->getMessage()
            // ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Unsubscribe from topic
     */
    public function unsubscribeFromTopic(string $token, string $topic)
    {
        try {
            $this->messaging->unsubscribeFromTopic($topic, [$token]);
            
            // Log::info('✅ Unsubscribed from topic', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'topic' => $topic
            // ]);

            return ['success' => true];

        } catch (\Exception $e) {
            // Log::error('❌ Topic unsubscription failed', [
            //     'token' => substr($token, 0, 20) . '...',
            //     'topic' => $topic,
            //     'error' => $e->getMessage()
            // ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
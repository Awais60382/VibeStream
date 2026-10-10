<?php

function vs_subscription_user_id(): int
{
    $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? $id : 0;
}

function vs_subscription_key(string $channelName): string
{
    return 'ch:' . mb_strtolower(trim($channelName));
}

function vs_subscription_has_subscriber_count(mysqli $conn): bool
{
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    $result = $conn->query("SHOW COLUMNS FROM `videos` LIKE 'subscribers'");
    if (!$result) {
        throw new RuntimeException('Could not verify channel subscriber counts.');
    }
    $hasColumn = $result->num_rows > 0;
    $result->free();
    return $hasColumn;
}

function vs_subscription_session_sync(mysqli $conn): void
{
    if (!isset($_SESSION['subscribed_channels']) || !is_array($_SESSION['subscribed_channels'])) {
        $_SESSION['subscribed_channels'] = [];
    }

    $userId = vs_subscription_user_id();
    if ($userId <= 0) {
        return;
    }

    try {
        $stmt = $conn->prepare('SELECT channel_name FROM user_subscriptions WHERE subscriber_id = ?');
    } catch (mysqli_sql_exception $error) {
        if ($error->getCode() === 1146) {
            throw new RuntimeException('Subscriptions are not set up yet. Run the subscriptions database migration.', 0, $error);
        }
        throw $error;
    }
    if (!$stmt) {
        throw new RuntimeException('Could not load your subscriptions. Run the subscriptions database migration.');
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not load your subscriptions. Please try again.');
    }
    $result = $stmt->get_result();
    $channels = [];
    while ($row = $result->fetch_assoc()) {
        $channels[] = vs_subscription_key((string)$row['channel_name']);
    }
    $stmt->close();
    $_SESSION['subscribed_channels'] = array_values(array_unique($channels));
}

function vs_subscription_change(
    mysqli $conn,
    string $channelName,
    string $channelUsername,
    string $videoId,
    ?bool $subscribe = null
): array {
    $channelName = trim($channelName);
    $channelUsername = trim($channelUsername);
    if ($channelName === '') {
        $channelName = $channelUsername;
    }
    if ($channelName === '') {
        throw new RuntimeException('This channel cannot be subscribed to yet.');
    }

    if (!vs_subscription_has_subscriber_count($conn)) {
        throw new RuntimeException('Subscriptions are unavailable because the videos.subscribers column is missing.');
    }

    $userId = vs_subscription_user_id();
    $key = vs_subscription_key($channelName);
    $changed = false;

    if ($userId > 0) {
        if (!empty($_SESSION['username']) && strcasecmp($channelUsername, trim((string)$_SESSION['username'])) === 0) {
            throw new RuntimeException('You cannot subscribe to your own channel.');
        }
        $channelHash = hash('sha256', mb_strtolower($channelName));
        $exists = $conn->prepare('SELECT 1 FROM user_subscriptions WHERE subscriber_id = ? AND channel_key = ? LIMIT 1');
        if (!$exists) {
            throw new RuntimeException('Could not update your subscription. Run the subscriptions database migration.');
        }
        $exists->bind_param('is', $userId, $channelHash);
        if (!$exists->execute()) {
            $exists->close();
            throw new RuntimeException('Could not update your subscription. Please try again.');
        }
        $isSubscribed = (bool)$exists->get_result()->fetch_row();
        $exists->close();
        $shouldSubscribe = $subscribe ?? !$isSubscribed;

        if ($shouldSubscribe !== $isSubscribed) {
            if ($shouldSubscribe) {
                $insert = $conn->prepare('INSERT IGNORE INTO user_subscriptions (subscriber_id, channel_name, channel_username, channel_key) VALUES (?, ?, ?, ?)');
                if (!$insert) {
                    throw new RuntimeException('Could not save your subscription. Run the subscriptions database migration.');
                }
                $insert->bind_param('isss', $userId, $channelName, $channelUsername, $channelHash);
                if (!$insert->execute()) {
                    $insert->close();
                    throw new RuntimeException('Could not save your subscription. Please try again.');
                }
                $changed = $insert->affected_rows === 1;
                $insert->close();
                $isSubscribed = true;
            } else {
                $delete = $conn->prepare('DELETE FROM user_subscriptions WHERE subscriber_id = ? AND channel_key = ?');
                if (!$delete) {
                    throw new RuntimeException('Could not remove your subscription. Please try again.');
                }
                $delete->bind_param('is', $userId, $channelHash);
                if (!$delete->execute()) {
                    $delete->close();
                    throw new RuntimeException('Could not remove your subscription. Please try again.');
                }
                $changed = $delete->affected_rows === 1;
                $delete->close();
                $isSubscribed = false;
            }
        }
    } else {
        if (!isset($_SESSION['subscribed_channels']) || !is_array($_SESSION['subscribed_channels'])) {
            $_SESSION['subscribed_channels'] = [];
        }
        $isSubscribed = in_array($key, $_SESSION['subscribed_channels'], true);
        $shouldSubscribe = $subscribe ?? !$isSubscribed;
        if ($shouldSubscribe !== $isSubscribed) {
            $_SESSION['subscribed_channels'] = $shouldSubscribe
                ? array_values(array_unique(array_merge($_SESSION['subscribed_channels'], [$key])))
                : array_values(array_diff($_SESSION['subscribed_channels'], [$key]));
            $isSubscribed = $shouldSubscribe;
            $changed = true;
        }
    }

    if ($changed) {
        $delta = $isSubscribed ? 1 : -1;
        $sql = $channelUsername !== ''
            ? 'UPDATE videos SET subscribers = GREATEST(COALESCE(subscribers, 0) + ?, 0) WHERE username = ?'
            : 'UPDATE videos SET subscribers = GREATEST(COALESCE(subscribers, 0) + ?, 0) WHERE channel_name = ?';
        $count = $conn->prepare($sql);
        if (!$count) {
            throw new RuntimeException('Your subscription was saved, but the channel count could not be updated.');
        }
        $countTarget = $channelUsername !== '' ? $channelUsername : $channelName;
        $count->bind_param('is', $delta, $countTarget);
        if (!$count->execute()) {
            $count->close();
            throw new RuntimeException('Your subscription was saved, but the channel count could not be updated.');
        }
        $count->close();
    }

    $subs = 0;
    $countStmt = $conn->prepare('SELECT COALESCE(subscribers, 0) AS n FROM videos WHERE id = ? LIMIT 1');
    if ($countStmt) {
        $countStmt->bind_param('s', $videoId);
        if ($countStmt->execute()) {
            $subs = (int)($countStmt->get_result()->fetch_assoc()['n'] ?? 0);
        }
        $countStmt->close();
    }

    if (vs_subscription_user_id() <= 0) {
        return ['subscribed' => $isSubscribed, 'subscribers' => $subs, 'channel' => $channelName];
    }
    if ($isSubscribed) {
        $_SESSION['subscribed_channels'] = array_values(array_unique(array_merge($_SESSION['subscribed_channels'] ?? [], [$key])));
    } else {
        $_SESSION['subscribed_channels'] = array_values(array_diff($_SESSION['subscribed_channels'] ?? [], [$key]));
    }

    return ['subscribed' => $isSubscribed, 'subscribers' => $subs, 'channel' => $channelName];
}
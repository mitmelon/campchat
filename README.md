# CampChat

CampChat is a secure, scalable group chat engine built with PHP, Slim Framework, MongoDB, Redis, RabbitMQ, and Monolog. It supports user management, group creation, end-to-end encrypted messaging, and extensible bots with webhook support, similar to Telegram's bot system. Designed for real-time communication, CampChat offers features like media sharing, message reactions, pinned messages, and group permissions, with a focus on privacy and performance.

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Installation](#installation)
- [API Endpoints](#api-endpoints)
  - [Users](#users)
  - [Groups](#groups)
  - [Messages](#messages)
  - [Bots](#bots)
- [Usage Examples](#usage-examples)
- [Future Updates](#future-updates)
- [Contributing](#contributing)
- [License](#license)

## Features

- **User Management**: Create, update, and delete user accounts.
- **Group Chats**: Create and manage groups with admins, permissions, and bot integration.
- **Encrypted Messaging**: Send text, media (photos, videos, documents), locations, and contacts with end-to-end encryption for private and group chats.
- **Message Features**: Edit, delete, forward, react to, and pin messages in groups.
- **Bots**: Create bots with custom commands and webhook support to handle group events (e.g., messages, member joins/leaves) and respond interactively.
- **Real-Time Processing**: Use RabbitMQ for asynchronous bot event handling and Redis for caching.
- **Logging**: Comprehensive logging with Monolog for debugging and monitoring.
- **Security**: JWT-based authentication, encrypted message content, and HTTPS webhook validation.

## Tech Stack

- **Backend**: PHP 8.1+, Slim Framework 4
- **Database**: MongoDB (for users, groups, messages, bots)
- **Caching**: Redis
- **Message Queue**: RabbitMQ (for bot event processing)
- **Logging**: Monolog
- **HTTP Client**: Guzzle (for webhook requests)
- **Encryption**: Custom encryption service for messages
- **Storage**: Custom storage service for media uploads

## Installation

### Prerequisites

- PHP 8.1+
- Composer
- MongoDB
- Redis
- RabbitMQ
- Web server (e.g., Nginx, Apache)

### Steps

1. **Clone the Repository**
   ```bash
   git clone https://github.com/mitmelon/campchat.git
   cd campchat
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Configure Environment**
   
   Copy `.env.example` to `.env` and update the following:
   ```env
    MONGODB_URI=mongodb://localhost:27017
    REDIS_HOST=localhost
    REDIS_PORT=6379
    RABBITMQ_HOST=localhost
    RABBITMQ_PORT=5672
    RABBITMQ_USER=campchat
    RABBITMQ_PASS=1234567890
    STORAGE_TYPE=local # or aws_s3
    STORAGE_PATH=/path/to/storage

    # If storage is set to use aws object storage
    AWS_REGION=us-east-1
    AWS_S3_BUCKET=campchat-messages
    AWS_ACCESS_KEY_ID=your_key
    AWS_SECRET_ACCESS_KEY=your_secret

   ```
   
   Ensure MongoDB, Redis, and RabbitMQ are running.

4. **Set Up Storage Directory**
   ```bash
   mkdir -p /path/to/storage
   chmod -R 775 /path/to/storage
   ```

5. **Run the Application**
   
   Use a web server or PHP's built-in server:
   ```bash
   composer start or php scripts/server.php start
   ```

6. **Start the Bot Worker**
   
   Run the bot worker to process group events:
   ```bash
   php src/Workers/BotWorker.php
   ```

7. **Access the API**
   
   The API is available at `http://localhost:8011/v1`.

## API Endpoints

Responses follow the format: `{ "ok": true, "result": {...} }` or `{ "ok": false, "error": "message" }`.

### Users

#### POST `/users/create`
Create a new user.

**Body:**
```json
{
  "first_name": "string",
  "last_name": "string",
  "username": "string",
  "password": "string",
  "phone": "string",
  "dob": "string",
  "country": "string",
  "gender": "string",
}
```

**Response:**
```json
{
  "ok": true,
  "result": {
    "id": "string"
  }
}
```

**Status:** 201 on success, 400 on error

#### POST `/users/login`
Authenticate a user and get a JWT token.

**Body:**
```json
{
  "username": "string",
  "password": "string"
}
```

**Response:**
```json
{
  "ok": true,
  "result": {
    "token": "string"
  }
}
```

**Status:** 200 on success, 401 on invalid credentials

#### GET `/users/{id}`
Get user details.

**Response:**
```json
{
  "ok": true,
  "result": {
    "id": "string",
    "username": "string"
  }
}
```

**Status:** 200 on success, 404 if not found

#### PUT `/users/{id}`
Update user details.

**Body:**
```json
{
  "username": "string",
  "public_key": "string",
  "private_key": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### DELETE `/users/{id}`
Delete a user.

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 404 if not found

### Groups

#### POST `/groups`
Create a group.

**Body:**
```json
{
  "name": "string",
  "creator_id": "string",
  "members": ["string"],
  "permissions": {
    "locked": "bool",
    "allow_member_messages": "bool"
  }
}
```

**Response:**
```json
{
  "ok": true,
  "result": {
    "id": "string"
  }
}
```

**Status:** 201 on success, 400 on error

#### POST `/groups/{groupId}/members`
Add a member to a group.

**Body:**
```json
{
  "user_id": "string",
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### DELETE `/groups/{groupId}/members`
Remove a member from a group.

**Body:**
```json
{
  "user_id": "string",
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### DELETE `/groups/{groupId}/quit`
Quit a group.

**Body:**
```json
{
  "user_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### POST `/groups/{groupId}/admins`
Promote a member to admin.

**Body:**
```json
{
  "user_id": "string",
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### DELETE `/groups/{groupId}/admins`
Demote an admin.

**Body:**
```json
{
  "user_id": "string",
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### PUT `/groups/{groupId}/permissions`
Update group permissions.

**Body:**
```json
{
  "admin_id": "string",
  "permissions": {
    "locked": "bool",
    "allow_member_messages": "bool"
  }
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### PUT `/groups/{groupId}/details`
Update group details.

**Body:**
```json
{
  "admin_id": "string",
  "name": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### DELETE `/groups/{groupId}`
Delete a group.

**Body:**
```json
{
  "creator_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### POST `/groups/{groupId}/bots`
Add a bot to a group.

**Body:**
```json
{
  "bot_id": "string",
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### DELETE `/groups/{groupId}/bots`
Remove a bot from a group.

**Body:**
```json
{
  "bot_id": "string",
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

### Messages

#### POST `/messages/send/{type}`
Send a message. Types: `text`, `photo`, `video`, `audio`, `document`, `animation`, `voice`, `location`, `contact`.

**Body (text):**
```json
{
  "sender_id": "string",
  "recipient_id": "string|null",
  "group_id": "string|null",
  "content": "string",
  "reply_to_message_id": "string|null",
  "entities": []
}
```

**Body (media):** Form-data with media file and:
```json
{
  "sender_id": "string",
  "recipient_id": "string|null",
  "group_id": "string|null",
  "caption": "string|null",
  "reply_to_message_id": "string|null"
}
```

**Body (location):**
```json
{
  "sender_id": "string",
  "recipient_id": "string|null",
  "group_id": "string|null",
  "latitude": "float",
  "longitude": "float"
}
```

**Body (contact):**
```json
{
  "sender_id": "string",
  "recipient_id": "string|null",
  "group_id": "string|null",
  "phone_number": "string",
  "first_name": "string",
  "last_name": "string|null"
}
```

**Response:**
```json
{
  "ok": true,
  "result": {
    "id": "string",
    "media_url": "string|null"
  }
}
```

**Status:** 201 on success, 400 on error

#### POST `/messages/edit/{messageId}`
Edit a message.

**Body:**
```json
{
  "content": "string|null",
  "caption": "string|null"
}
```
Or form-data with media

**Response:**
```json
{
  "ok": true,
  "media_url": "string|null"
}
```

**Status:** 200 on success, 400 on error

#### POST `/messages/delete/{messageId}`
Delete a message.

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### POST `/messages/forward`
Forward a message.

**Body:**
```json
{
  "message_id": "string",
  "from_user_id": "string",
  "to_user_id": "string|null",
  "to_group_id": "string|null"
}
```

**Response:**
```json
{
  "ok": true,
  "result": {
    "id": "string",
    "media_url": "string|null"
  }
}
```

**Status:** 201 on success, 400 on error

#### POST `/messages/reaction`
Set a reaction on a message.

**Body:**
```json
{
  "message_id": "string",
  "user_id": "string",
  "reaction": "👍|👎|❤️|🔥|🎉"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### GET `/messages/history/{recipientId}`
Get private chat history.

**Query:** `sender_id=string&limit=int&skip=int`

**Response:**
```json
{
  "ok": true,
  "result": [
    {
      "id": "string",
      "sender_id": "string|null",
      "bot_id": "string|null"
    }
  ]
}
```

**Status:** 200 on success, 400 on error

#### GET `/messages/group/{groupId}/messages`
Get group messages.

**Query:** `user_id=string&limit=int&skip=int`

**Response:**
```json
{
  "ok": true,
  "result": {
    "messages": [],
    "pinned_message": null
  }
}
```

**Status:** 200 on success, 400 on error

#### POST `/messages/pin/{groupId}`
Pin a message in a group.

**Body:**
```json
{
  "message_id": "string",
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### POST `/messages/unpin/{groupId}`
Unpin a message in a group.

**Body:**
```json
{
  "admin_id": "string"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### GET `/messages/updates`
Get real-time message updates.

**Query:** `offset=int&limit=int&timeout=int`

**Response:**
```json
{
  "ok": true,
  "result": []
}
```

**Status:** 200 on success, 400 on error

### Bots

#### POST `/bots`
Create a bot.

**Body:**
```json
{
  "user_id": "string",
  "name": "string",
  "commands": {
    "command": "response"
  },
  "webhook_url": "string|null"
}
```

**Response:**
```json
{
  "ok": true,
  "result": {
    "id": "string"
  }
}
```

**Status:** 201 on success, 400 on error

#### PUT `/bots/{botId}/commands`
Update bot commands.

**Body:**
```json
{
  "user_id": "string",
  "commands": {
    "command": "response"
  }
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

#### PUT `/bots/{botId}/webhook`
Set or clear bot webhook.

**Body:**
```json
{
  "user_id": "string",
  "webhook_url": "string|null"
}
```

**Response:**
```json
{
  "ok": true
}
```

**Status:** 200 on success, 400 on error

## Usage Examples

### Create a User

```bash
curl -X POST http://localhost:8011/users/create \
-H "Content-Type: application/json" \
-d '{"username":"alice","password":"secure123"}'
```

**Response:**
```json
{"ok":true,"result":{"id":"user1234567890abcdef"}}
```

### Login

```bash
curl -X POST http://localhost:8011/users/login \
-H "Content-Type: application/json" \
-d '{"username":"alice","password":"secure123"}'
```

**Response:**
```json
{"ok":true,"result":{"token":"jwt.token.here"}}
```

### Create a Group

```bash
curl -X POST http://localhost:8011/groups \
-H "Content-Type: application/json" \
-d '{"name":"My Group","creator_id":"user1234567890abcdef","members":["user456"],"permissions":{"locked":false,"allow_member_messages":true}}'
```

**Response:**
```json
{"ok":true,"result":{"id":"group1234567890abcdef"}}
```

### Send a Text Message

```bash
curl -X POST http://localhost:8011/messages/send/text \
-H "Content-Type: application/json" \
-d '{"sender_id":"user1234567890abcdef","group_id":"group1234567890abcdef","content":"Hello, group!"}'
```

**Response:**
```json
{"ok":true,"result":{"id":"msg1234567890abcdef","media_url":null}}
```

### Create a Bot with Webhook

```bash
curl -X POST http://localhost:8011/bots \
-H "Content-Type: application/json" \
-d '{"user_id":"user1234567890abcdef","name":"WelcomeBot","commands":{"welcome":"Hello, group!"},"webhook_url":"https://example.com/bot-webhook"}'
```

**Response:**
```json
{"ok":true,"result":{"id":"bot1234567890abcdef"}}
```

### Webhook Event

When a user sends `/welcome@WelcomeBot` in a group, CampChat POSTs to `https://example.com/bot-webhook`:

```json
{
  "bot_id": "bot1234567890abcdef",
  "event": "message",
  "group_id": "group1234567890abcdef",
  "user_id": "user1234567890abcdef",
  "timestamp": 1740771420,
  "message": {
    "id": "msg1234567890abcdef",
    "sender_id": "user1234567890abcdef",
    "content": "/welcome@WelcomeBot",
    "type": "text",
    "created_at": "2025-05-27T21:57:00+03:00"
  },
  "group": {
    "id": "group1234567890abcdef",
    "name": "My Group"
  }
}
```

**Webhook Response:**
```json
{
  "ok": true,
  "result": {
    "type": "text",
    "content": "Welcome to the group!",
    "keyboard": [[{"text": "Help", "action": "help"}]]
  }
}
```

CampChat posts the response as a bot message in the group.

## Future Updates

CampChat is poised for significant enhancements to make it a next-generation messaging platform. Planned features include:

### Core Improvements

- **Complete Docs**: Design the complete documentation pages for using the project and showing some examples.
- **Max Connections**: Implement limits on concurrent webhook connections per bot to optimize performance and prevent abuse, with configurable settings for bot creators.
- **Enhanced Chat Engine Security**: Strengthen encryption with quantum-resistant algorithms, add message integrity checks, and implement secure key rotation for users and groups.
- **Polls and Quizzes**: Support interactive message types for polls (single/multiple choice) and quizzes with correct answers, stored in the messages collection and rendered in clients.
- **Phone Notifications**: Integrate SMS or voice call notifications for critical events (e.g., group invites, pinned messages) using a third-party telephony API.
- **Push Notifications**: Add Web Push and mobile push notifications (via FCM/APNs) for real-time message and event alerts, with user-configurable preferences.
- **AI Bot Integration**: Enable bots to integrate with AI models (e.g., GPT) for natural language processing, allowing conversational responses, sentiment analysis, or automated moderation.
- **User Blocking**: Allow users to block others, preventing messages and group interactions, with a `blocked_users` list in the user model.
- **Anonymity**: Support anonymous group participation with temporary pseudonymous identities, hiding real user IDs from messages and group metadata.

### Authentication & Security

- **User Login**: Implement a secure, user-friendly login system with session management and password recovery.
- **OAuth 2.0**: Add OAuth 2.0 for third-party app authentication, enabling seamless login via Google, Facebook, etc., with secure token management.
- **Two-Factor Authentication (2FA)**: Enhance security with 2FA via SMS, authenticator apps, or email for login and sensitive actions.

### Next-Generation Social Media Features

- **Stories**: Introduce ephemeral stories (text, images, videos) visible to group members or private contacts.
- **Live Streaming**: Enable group-based live audio/video streaming for events or discussions.
- **Reels**: Support short, engaging video content with filters and effects, shareable in groups.
- **Custom Emojis and Stickers**: Allow users to create and share custom emoji packs and stickers.
- **Social Profiles**: Add customizable user profiles with bios, avatars, and activity feeds.
- **Gamification**: Introduce badges, leaderboards, and rewards for active participation.

### Webhook Enhancements

- **Certificate Support**: Allow bot creators to upload SSL certificates for webhook verification.
- **Direct Message Support**: Extend webhooks to handle private messages to bots.
- **Additional Message Types**: Support webhook responses for locations, contacts, polls, and more.

These updates will be prioritized based on community feedback and strategic goals.

## Contributing

Contributions are welcome! Please:

1. Open an issue to discuss your idea.
2. Fork the repository and create a pull request.
3. Follow the existing code structure and coding standards.
4. Ensure tests pass (TBD: add test suite).

## License

This project is licensed under the MIT License. See the LICENSE file for more details.
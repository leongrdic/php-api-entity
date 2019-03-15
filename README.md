# php-api-entity
A class that is extended by an API resource class and enables direct access to a specific entity class with permission management, fetching and setting entity data and listing.

## Getting started
### Using Composer
You can install `php-api-entity` by running
```
php composer.phar require leongrdic/api-entity
```

### Not using Composer
You can simply require the `EntityAPI.php` file from the `src/` folder at the beginning of your script:
```php
require_once('EntityAPI.php');
```

### Example usage
```php
class UserAPI extends \Le\EntityAPI {
  protected static $entity_options = [
    'class' => User::class, // the entity class to use
    'cache' => 3600, // default cache time in seconds for fetched entities
    'list_per_page' => 10, // default number of items per page when listing entities
    'props' => [ // default read access level is PUBLIC and for write it's PRIVATE
      'email' => [ 'read' => \Le\EntityAPI::ACCESS_PROTECTED ], // set the read access level to PROTECTED
      'password' => [ 'read' => \Le\EntityAPI::ACCESS_PRIVATE ], // set the read access level to PRIVATE
      'status' => ['write' => \Le\EntityAPI::ACCESS_PROTECTED ] // set the write access level to PROTECTED
    ]
  ];
}
```

## Permission handling
There are three default levels of access currently available: `PUBLIC` (intended for everyone to see), `PROTECTED` (intended for authenticated requests/users) and `PRIVATE` (intended for internal properties not meant to be accessed from outside).

Whenever some properties of a specific entity are being read or written by the `\Le\EntityAPI` methods, a static method `entity_access($id)` is called on the extending resource class with the ID of the entity to check the access level granted.

By default that method is defined in `\Le\EntityAPI` so it's inherited by all classes extending it, and it returns a value of `\Le\EntityAPI::ACCESS_PUBLIC` which in other words, means `PUBLIC` level acess.

It's also possible to return `\Le\EntityAPI::ACCESS_DENY` which will result in a `HTTP 404 NOT FOUND` error instead of a `HTTP 403 FORBIDDEN` response or hidden properties from the response.

It's possible to override the `entity_access()` method to implement logic that checks which level of access the request should be granted.

Example:
```php
public static function entity_access($id){
  // return \Le\EntityAPI::ACCESS_DENY; // if we want to DENY access completely

  if(\Le\API::$session->get('user') === $id) // logged in user
    return \Le\EntityAPI::ACCESS_PROTECTED; // grant the PROTECTED access level
  else // another user (not logged in)
    return \Le\EntityAPI::ACCESS_PUBLIC; // grant the PUBLIC access level
}
```

## Static methods and API actions
### `get_get($params)`
This is an API action method, and `$params` is passed as defined by API specification.

#### `$params`
`$params['path'][0]` is expected to be an entity `ID`, with an optional `:[HASH]` after the ID which forces the hash to be validated before fetching the whole object (to prevent fetching if there weren't any changes).

You can also list multiple IDs (and their hashes) separated with commas (`,`) which will result in a `207` HTTP response containing multiple responses.

#### Permission handling

The `static::$entity_options` array will be checked for a `props` element containing a list of properties and their appropriate `read` permissions. All properties have the `PUBLIC` access level of `read` permissions by default.

#### Example usages
With JSON-encoded responses (this is dependent on the setup)

`GET /[resource]/get/23`
```
HTTP 200 OK
{
  "column": "value",
  ...
}
```
If the Entity doesn't exits:
```
HTTP 404 NOT FOUND
```

`GET /[resource]/get/23:73641529`

If the hash matches the one of the entity:
```
HTTP 304 NOT MODIFIED
```
Otherwise, a response same as in previous example will be sent.

`GET /[resource]/get/17,23:73641529`
```
HTTP 207 MULTI STATUS
[
    {
        "id": "17",
        "status": 404,
        "body": {
            "message": "entity not found"
        }
    },
    {
        "id": "23",
        "status": 304
    }
]
```

### `post_set($params)`
This is an API action method, and `$params` is passed as defined by API specification.

#### `$params`
`$params['path'][0]` is expected to be an entity `ID`.

`$params['data']` is expected to contain a key-value list of data that needs to be updated/set on the entity.

#### Permission handling

The `static::$entity_options` array will be checked for a `props` element containing a list of properties and their appropriate `write` permissions. All properties have the `PRIVATE` access level of `write` permissions by default.

#### Responses
```
HTTP 204 NO CONTENT
```
If permission wasn't granted for any properties:
```
HTTP 403 FORBIDDEN
```
If entity doesn't exist or access level was `DENY`:
```
HTTP 404 NOT FOUND
```

### `fetch($params, $entities)`
This is a static method not accessible through the API and it allows you to easily fetch entities from your API action methods that were referenced by their IDs in `$params`.

#### Parameters
`$params` should be passed from the API action method

`$entities` is an array containing a list of entitiy classes and instructions where the ID can be found:
```php
[
  [User::class, 'path', 0], // the User entity ID should be in $params['path'][0]
  [Session::class, 'data', 'session'], // the Session entity ID should be in $params['data']['session']
  ...
]
```

#### Return
If an entity ID isn't provided in $params or a wrong one is provided, the function will throw an `APIException`:
```
HTTP 404 NOT FOUND
{
  "message": "User not found",
  "code": "path_0"
}
```

If all entities are found and loaded successfully, the method will return an array containing those entity objects in the same order as passed in the `$entities` parameter:
```php
[
  User object,
  Session object,
  ...
]
```

#### Example usage
```php
  list($user, $session) = \Le\EntityAPI::fetch($params, [
    [User::class, 'query', 'user'],
    [Session::class, 'data', 'session']
  ]);

  $user->...
  $session->...
```

### `list($conditions, $additional, $page_number, $per_page)`
This is a static method not accessible through the API, so it has to be implemented in one of your own API action methods. Use the documentation below for help.

#### Parameters
`$conditions` and `$additional` are arrays passed to the Entity `find()` method

`$page_number` is the current page number; defaults to 0

`$per_page` is the optional number of entities per page; defaults to the value defined in `self::$entity_options` or if not set, `10`

#### Return
```php
[
  'page_count' => 3,
  'data' => [
    [
      'id' => 1,
      'hash' => 37251185 // if enabled in the Entity class
    ],
    ...
  ]
]
```

## Overriding or disabling methods

You can override any method from `\Le\EntityAPI`, including the API actions.

If you wish to make a built-in API action seem like it wasn't ever implemented (for example disable the `post_set()` API action), put the following in you API resource class:
```php
public static function post_set($params){
  \Le\EntityAPI::disabled();
}
```

To call the parent method from `\Le\EntityAPI` when overriding it:
```php
public static function get_get($params){
  if(!isset($params['path'][0])) // when the entity ID is missing
    return parent::get_get([
      'path' => [ \Le\API::$session->get('user') ] // pass an entity ID to the parent method
    ]);

  return parent::get_get($params); // just pass the original parameters to the parent method
}
```

## Disclaimer
I do not guarantee that this code is 100% secure and it should be used at your own responsibility.

If you find any errors or mistakes, open a ticket or create a pull request.

Please feel free to leave a comment and share your thoughts on this!

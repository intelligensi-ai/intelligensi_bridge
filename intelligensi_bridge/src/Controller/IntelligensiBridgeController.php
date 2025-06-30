<?php

namespace Drupal\intelligensi_bridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

/**
 * Defines Intelligensi Bridge controller routes.
 */
class IntelligensiBridgeController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new IntelligensiBridgeController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Returns site info.
   */
  /**
   * Returns site information.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing site information.
   */
  public function siteInfo(): JsonResponse {
    $config = $this->config('system.site');
    return new JsonResponse([
      'site_name' => $config->get('name'),
      'site_slogan' => $config->get('slogan'),
      'timestamp' => $this->time->getCurrentTime(),
    ]);
  }

  /**
   * Updates the homepage node body.
   */
  /**
   * Updates the homepage node with new content.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success or failure.
   */
  public function updateHomepage(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    // Validate input
    if (empty($data['update_text'])) {
      return new JsonResponse(['error' => 'Missing required field: update_text'], 400);
    }

    // Sanitize input
    $update_text = filter_var($data['update_text'], FILTER_SANITIZE_STRING);
    
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $query = $node_storage->getQuery()
        ->condition('status', NodeInterface::PUBLISHED)
        ->condition('promote', 1)
        ->sort('created', 'DESC')
        ->range(0, 1)
        ->accessCheck(TRUE);
      
      $nids = $query->execute();

      if (!empty($nids)) {
        $node = $node_storage->load(reset($nids));
        if ($node && $node->hasField('body')) {
          $current = $node->get('body')->value;
          
          // Use Drupal's XSS filtering
          $node->set('body', [
            'value' => '<p><strong>' . $this->t('@text', ['@text' => $update_text]) . '</strong></p>' . $current,
            'format' => 'basic_html',
          ]);
          
          $node->setNewRevision(TRUE);
          $node->setRevisionUserId($this->currentUser->id());
          $node->setRevisionCreationTime($this->time->getRequestTime());
          $node->setRevisionLogMessage('Updated via Intelligensi Bridge API');
          
          $node->save();
          
          return new JsonResponse([
            'message' => 'Homepage updated successfully!',
            'nid' => $node->id(),
            'changed' => $node->getChangedTime(),
          ]);
        }
      }

      return new JsonResponse(['error' => 'No promoted homepage node found'], 404);
    } catch (\Exception $e) {
      \Drupal::logger('intelligensi_bridge')->error('Error updating homepage: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['error' => 'An error occurred while updating the homepage'], 500);
    }
  }

  /**
   * Exports published nodes.
   */
  /**
   * Exports published nodes with pagination.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing node data.
   */
  public function bulkExport(Request $request): JsonResponse {
    // Get pagination parameters
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = min(50, max(1, (int) $request->query->get('limit', 20)));
    $offset = ($page - 1) * $limit;
    
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      
      // Count total nodes for pagination
      $count_query = $node_storage->getQuery()
        ->condition('status', NodeInterface::PUBLISHED)
        ->accessCheck(TRUE);
      $total = $count_query->count()->execute();
      
      // Get nodes with pagination
      $query = $node_storage->getQuery()
        ->condition('status', NodeInterface::PUBLISHED)
        ->sort('created', 'DESC')
        ->range($offset, $limit)
        ->accessCheck(TRUE);
      
      $nids = $query->execute();
      $nodes = [];
      
      foreach ($node_storage->loadMultiple($nids) as $node) {
        if ($node->access('view')) {
          $nodes[] = [
            'nid' => $node->id(),
            'uuid' => $node->uuid(),
            'title' => $node->label(),
            'created' => $node->getCreatedTime(),
            'changed' => $node->changed->value,
            'status' => $node->isPublished(),
            'type' => $node->getType(),
            'body' => $node->get('body')->value ?? '',
            'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
          ];
        }
      }
      
      $response = [
        'data' => $nodes,
        'pagination' => [
          'total' => (int) $total,
          'page' => $page,
          'limit' => $limit,
          'pages' => ceil($total / $limit),
        ],
      ];
      
      // Add cache tags for invalidation
      $response = new JsonResponse($response);
      $response->setMaxAge(300); // Cache for 5 minutes
      $response->headers->set('X-Total-Count', $total);
      
      return $response;
      
    } catch (\Exception $e) {
      \Drupal::logger('intelligensi_bridge')->error('Error during bulk export: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['error' => 'An error occurred while exporting nodes'], 500);
    }
  }

  /**
   * Imports content as nodes via POST.
   */
  /**
   * Imports nodes from JSON data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with created node IDs or error message.
   */
  public function importNodes(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $created = [];
    $errors = [];

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid payload: expected JSON array'], 400);
    }
    
    // Limit the number of nodes that can be imported in one request
    if (count($data) > 50) {
      return new JsonResponse(['error' => 'Too many items. Maximum 50 items per request.'], 400);
    }

    foreach ($data as $index => $item) {
      try {
        // Validate required fields
        if (empty($item['title'])) {
          $errors[] = "Item {$index}: Missing required field 'title'";
          continue;
        }
        
        // Sanitize input
        $title = filter_var($item['title'], FILTER_SANITIZE_STRING);
        $body = isset($item['body']) ? filter_var($item['body'], FILTER_SANITIZE_STRING) : '';
        $type = !empty($item['type']) ? filter_var($item['type'], FILTER_SANITIZE_STRING) : 'page';
        
        // Validate node type exists
        $node_type = $this->entityTypeManager->getStorage('node_type')->load($type);
        if (!$node_type) {
          $errors[] = "Item {$index}: Invalid content type '{$type}'";
          continue;
        }
        
        // Create node
        $node = Node::create([
          'type' => $type,
          'title' => $title,
          'body' => [
            'value' => $body,
            'format' => 'basic_html',
          ],
          'status' => 1,
          'uid' => $this->currentUser->id(),
        ]);
        
        $node->enforceIsNew();
        $node->save();
        
        $created[] = [
          'id' => $node->id(),
          'uuid' => $node->uuid(),
          'title' => $node->label(),
        ];
        
      } catch (\Exception $e) {
        $errors[] = "Item {$index}: " . $e->getMessage();
        \Drupal::logger('intelligensi_bridge')->error('Error importing node: @error', ['@error' => $e->getMessage()]);
      }
    }
    
    $response = [
      'created' => $created,
      'count' => count($created),
    ];
    
    if (!empty($errors)) {
      $response['errors'] = $errors;
      $response['error_count'] = count($errors);
      
      if (empty($created)) {
        // If no items were created and there are errors, return error status
        return new JsonResponse($response, 400);
      }
      
      // If some items were created but there were errors, return 207 (Multi-Status)
      return new JsonResponse($response, 207);
    }
    
    return new JsonResponse($response);
  }
}

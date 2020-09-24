<?php

/**
 * @file
 * Contains "scan taxonomy tree" related functions.
 */

use Drupal\Component\Serialization\Json;

/**
 * Set array value by given path.
 *
 * @param array $array
 *   Array to set value.
 * @param array $path
 *   Exact path of array.
 * @param object $term
 *   Term data.
 */
function _set_array_value_by_path(array &$array, array $path, \stdClass $term) {
  $value = [
    'name' => $term->name,
    'depth' => $term->depth,
    'children' => [],
  ];

  // In case if path not specified, then this is a first layer.
  if (empty($path)) {
    $array[$term->tid] = $value;
    return;
  }

  $dest = &$array;

  while ($key = array_shift($path)) {
    // Move the reference deeper.
    $dest = &$dest[$key]['children'];
  }
  $dest[$term->tid] = $value;
}

/**
 * Check the term.
 *
 * @param object $term
 *   Term object containing data fetch from {taxonomy_term__parent}.
 *
 * @return bool
 *   TRUE if the taxonomy term checked FALSE otherwise.
 */
function openideal_check_term_status(\stdClass $term) {
  // @Todo: implement some logic.
  return TRUE;
}

/**
 * Build JSON representation of term tree for given vocabulary ID.
 *
 * @param string $vid
 *   Vocabulary ID to retrieve terms for.
 * @param int $parent
 *   The term ID under which to generate the tree. If 0, generate the tree
 *   for the entire vocabulary.
 * @param int $max_depth
 *   The number of levels of the tree to return. Leave NULL to return all
 *   levels.
 *
 * @return string
 *   The encoded data.
 */
function build_json_term_tree($vid, $parent = 0, $max_depth = NULL) {
  $children = [];
  $parents = [];
  $json_tree = [];
  $tree = [];

  $query = \Drupal::database()->select('taxonomy_term_field_data', 't');
  $query->join('taxonomy_term__parent', 'p', 't.tid = p.entity_id');
  $query->addExpression('parent_target_id', 'parent');
  $result = $query
    ->addTag('taxonomy_term_access')
    ->fields('t')
    ->condition('t.vid', $vid)
    ->condition('t.default_langcode', 1)
    ->orderBy('t.weight')
    ->orderBy('t.name')
    ->execute();

  foreach ($result as $term) {
    $children[$term->parent][] = $term->tid;
    $parents[$term->tid][] = $term->parent;
    $tree[$term->tid] = $term;
  }

  $max_depth = (!isset($max_depth)) ? count($children) : $max_depth;

  // Keeps track of the parents we have to process, the last entry is used
  // for the next processing step.
  $process_parents = [];
  $process_parents[] = $parent;

  // Loops over the parent terms and adds its children to the tree array.
  // Uses a loop instead of a recursion, because it's more efficient.
  while (count($process_parents)) {
    $parent = array_pop($process_parents);
    // The number of parents determines the current depth.
    $depth = count($process_parents);
    if ($max_depth > $depth && !empty($children[$parent])) {
      $has_children = FALSE;
      $child = current($children[$parent]);
      do {
        if (empty($child)) {
          break;
        }

        $term = $tree[$child];
        $tid = $term->tid;

        if (!openideal_check_term_status($term)) {
          // In case if the depth is 0, move pointer
          // so that we get the correct term the next time.
          if ($child = next($children[$parent])) {
            $has_children = TRUE;

            $process_parents[] = $parent;
          }
          break;
        }

        if (isset($parents[$term->tid])) {
          // Clone the term so that the depth attribute remains correct
          // in the event of multiple parents.
          $term = clone $term;
        }
        $term->depth = $depth;
        // Set process parents for json response.
        $parents_path = $process_parents;

        // Remove "0" key because it's a first layer of tree.
        unset($parents_path[0]);

        // Need to push current parent,
        // because it's not saved inside process_parents.
        if ($parent != "0") {
          array_push($parents_path, $parent);
        }

        _set_array_value_by_path($json_tree, $parents_path, $term);

        if (!empty($children[$tid])) {
          $has_children = TRUE;

          // We have to continue with this parent later.
          $process_parents[] = $parent;
          // Use the current term as parent for the next iteration.
          $process_parents[] = $tid;

          // Reset pointers for child lists because we step in there more
          // often with multi parents.
          reset($children[$tid]);
          // Move pointer so that we get the correct term the next time.
          next($children[$parent]);
          break;
        }
      } while ($child = next($children[$parent]));

      if (!$has_children) {
        // We processed all terms in this hierarchy-level, reset pointer
        // so that this function works the next time it gets called.
        reset($children[$parent]);
      }
    }
  }
  return Json::encode($json_tree);
}

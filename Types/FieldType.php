<?php

namespace CockpitQL\Types;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class FieldType {

    protected static $types = [];
    protected static $names = [];


    private static function getName($name) {

        if (!isset(self::$names[$name])) {
            self::$names[$name] = 0;
        } else {
            self::$names[$name]++;
            $name .= self::$names[$name];
        }

        return $name;
    }

    public static function buildFieldsDefinitions($meta) {

        $fields = [];

        foreach ($meta['fields'] as $field) {

            $def = self::getType($field);

            if ($def) {
                
                $fields[$field['name']] = $def;

                if ($field['type'] == 'text' && isset($field['options']['slug']) && $field['options']['slug']) {
                    $fields[$field['name'].'_slug'] = Type::string();
                }
            }
        }

        return $fields;
    }

    protected static function collectionLinkFieldType($name, $field, $collection) {

        $typeName = "{$name}CollectionLink";

        if (!isset(self::$types[$typeName])) {

            $linkType = new ObjectType([
                'name' => $typeName,
                'fields' => function() use($collection) {

                    $fields = array_merge([
                        '_id' => Type::nonNull(Type::string()),
                        '_created' => Type::nonNull(Type::int()),
                        '_modified' => Type::nonNull(Type::int())
                    ], FieldType::buildFieldsDefinitions($collection));

                    return $fields;
                }
            ]);

            self::$types[$typeName] = $linkType;
        }

        return self::$types[$typeName];
    }


    protected static function getType($field) {

        $def = [];

        switch ($field['type']) {
            case 'text':
            case 'textarea':
            case 'code':
            case 'code':
            case 'password':
            case 'wysiwyg':
            case 'markdown':
            case 'date':
            case 'file':
            case 'time':
            case 'color':
            case 'colortag':
            case 'select':

                if ($field['type'] == 'text' && isset($field['options']['type']) && $field['options']['type'] == 'number') {
                    $def['type'] = Type::int();
                } else {
                    $def['type'] = Type::string();
                }

                break;
            case 'boolean':
                $def['type'] = Type::boolean();
                break;
            case 'rating':
                $def['type'] = Type::int();
                break;
            case 'gallery':
                $def['type'] = Type::listOf(new ObjectType([
                    'name' => uniqid('gallery_image'),
                    'fields' => [
                        'path' => Type::string(),
                        'meta' => JsonType::instance()
                    ]
                ]));
                break;
            case 'multipleselect':
            case 'access-list':
            case 'tags':
                $def['type'] = Type::listOf(Type::string());
                break;
            case 'image':
                $def['type'] = new ObjectType([
                    'name' => uniqid('image'),
                    'fields' => [
                        'path' => Type::string(),
                        'meta' => JsonType::instance()
                    ]
                ]);
                break;
            case 'asset':
                $def['type'] = new ObjectType([
                    'name' => uniqid('asset'),
                    'fields' => [
                        '_id' => Type::string(),
                        'title' => Type::string(),
                        'path' => Type::string(),
                        'mime' => Type::string(),
                        'tags' => Type::listOf(Type::string()),
                        'colors' => Type::listOf(Type::string()),
                    ]
                ]);
                break;

            case 'location':
                $def['type'] = new ObjectType([
                    'name' => uniqid('location'),
                    'fields' => [
                        'address' => Type::string(),
                        'lat' => Type::float(),
                        'lng' => Type::float()
                    ]
                ]);
                break;

            case 'layout':
            case 'layout-grid':
                $def['type'] = JsonType::instance();
                break;

            case 'set':
                $def['type'] = new ObjectType([
                    'name' => self::getName('Set'.ucfirst($field['name'])),
                    'fields' => self::buildFieldsDefinitions($field['options'])
                ]);
                break;

            case 'repeater':

                if (isset($field['options']['field'])) {

                    $field['options']['field']['name'] = 'RepeaterItemValue'.ucfirst($field['name']);

                    $typeRepeater = new ObjectType([
                        'name' => self::getName('RepeaterItem'.ucfirst($field['name'])),
                        'fields' => [
                            'value' => self::getType($field['options']['field'])
                        ]
                    ]);

                } else {

                    $typeRepeater = new ObjectType([
                        'name' => self::getName('RepeaterItem'.ucfirst($field['name'])),
                        'fields' => [
                            'value' => JsonType::instance()
                        ]
                    ]);
                }

                $def['type'] = Type::listOf($typeRepeater);
                break;

            case 'collectionlink':

                $collection = cockpit('collections')->collection($field['options']['link']);

                if (!$collection) {
                    break;
                }

                $linkType = self::collectionLinkFieldType($field['options']['link'], $field, $collection);

                if (isset($field['options']['multiple']) && $field['options']['multiple']) {
                    $def['type'] =  Type::listOf($linkType);
                    $def['args'] = [
                        'limit' => Type::int(),
                        'skip' => Type::int(),
                        'sort' => JsonType::instance(),
                    ];
                    $def['resolve'] = function ($root, $args) use ($field) {
                        if (!is_array($root[$field['name']])) return [];

                        $app = cockpit();
                        $link = $field['options']['link'];
                        $graphqlCache = $app->memory->get('graphql-cache', []);

                        if (!isset($graphqlCache[$link])) $graphqlCache[$link] = [];

                        $in = array_column($root[$field['name']], '_id');
                        $nonCachedIds = array_diff($in, array_keys($graphqlCache[$link]));
                        $cachedItems = array_values(
                            array_filter($graphqlCache[$link], function($id) use($in) {
                                return in_array($id, $in);
                            }, ARRAY_FILTER_USE_KEY)
                        );

                        if (empty($nonCachedIds)) return $cachedItems;

                        $options = [ 'filter' => [ '_id' => [ '$in' => $nonCachedIds ] ] ];

                        if (isset($args['limit'])) $options['limit'] = $args['limit'];
                        if (isset($args['skip'])) $options['skip'] = $args['skip'];
                        if (isset($args['sort'])) $options['sort'] = $args['sort'];


                        $collectionItems = cockpit('collections')->find($link, $options);
                        $result = array_merge($cachedItems, $collectionItems);
                        $sort = isset($options['sort']) ? $options['sort'] : [];

                        usort($result, function($a, $b) use($sort) {
                            if (empty($sort)) return strcmp($a['_id'], $b['_id']);

                            $sortResult = array_map(function($order, $field) use($a, $b) {
                                while (strpos($field, '.')) {
                                    $field = explode('.', $field, 2);
                                    $a = $a[$field[0]];
                                    $b = $b[$field[0]];
                                    $field = $field[1];
                                }

                                switch (true) {
                                    case is_int($a[$field]):
                                    case is_bool($a[$field]):
                                        return ($a[$field] - $b[$field]) * $order;
                                    case is_string($a[$field]):
                                        return strcmp($a[$field], $b[$field]) * $order;
                                    default:
                                        return 1;
                                }
                            }, $sort);

                            foreach ($sortResult as $item) {
                                if ($item !== 0) return $item;
                            }

                            return 0;
                        });
                        $ids = array_column($result, '_id');
                        $newCachedItems = array_combine($ids, $result);

                        $graphqlCache[$link] = array_merge(
                            $graphqlCache[$link],
                            $newCachedItems
                        );

                        $app->memory->set('graphql-cache', $graphqlCache);

                        return $result;
                    };
                } else {
                    $def['type'] = $linkType;
                    $def['resolve'] = function ($root) use ($field) {
                        $app = cockpit();
                        $link = $field['options']['link'];
                        $graphqlCache = $app->memory->get('graphql-cache', []);
                        $id = $root[$field['name']]['_id'];

                        if (isset($graphqlCache[$link][$id])) return $graphqlCache[$link][$id];

                        $collectionItem = cockpit('collections')->findOne($link, ['_id' => $id]);

                        $graphqlCache[$link][$id] = $collectionItem;

                        $app->memory->set('graphql-cache', $graphqlCache);

                        return $collectionItem;
                    };
                }

                break;
        }

        if (isset($def['type'], $field['required']) && $field['required']) {
            $def['type'] = Type::nonNull($def['type']);
        }

        return count($def) ? $def : null;
    }


    public static function instance($field) {
        self::getType($field);
    }
}

<?php
namespace Muffin\Slug\Model\Behavior;

use ArrayObject;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use InvalidArgumentException;
use Muffin\Slug\SluggerInterface;
use UnexpectedValueException;

/**
 * Slug behavior.
 */
class SlugBehavior extends Behavior
{

    /**
     * Configuration.
     *
     * - field: Name of the field (column) to hold the slug. Defaults to `slug`.
     * - displayField: Name of the field(s) to build the slug from. Defaults to
     *     the `\Cake\ORM\Table::displayField()`.
     * - separator: Defaults to `-`.
     * - replacements: Hash of characters (or strings) to custom replace before
     *     generating the slug.
     * - maxLength: Maximum length of a slug. Defaults to the field's limit as
     *     defined in the schema (when possible). Otherwise, no limit.
     * - slugger: Class that implements the `Muffin\Slug\SlugInterface`. Defaults
     *     to `Muffin\Slug\Slugger\CakeSlugger`.
     * - unique: Tells if slugs should be unique. Set this to a callable if you
     *     want to customize how unique slugs are generated. Defaults to `true`.
     * - scope: Extra conditions used when checking a slug for uniqueness.
     * - implementedEvents: Events this behavior listens to.
     * - implementedFinders: Custom finders implemented by this behavior.
     * - implementedMethods: Mixin methods directly accessible from the table.
     *
     * @var array
     */
    public $_defaultConfig = [
        'field' => 'slug',
        'displayField' => null,
        'separator' => '-',
        'replacements' => [
            '#' => 'hash',
            '?' => 'question',
            '+' => 'and',
            '&' => 'and',
        ],
        'maxLength' => null,
        'slugger' => 'Muffin\Slug\Slugger\CakeSlugger',
        'unique' => true,
        'scope' => [],
        'implementedEvents' => [
            'Model.buildValidator' => 'buildValidator',
            'Model.beforeSave' => 'beforeSave',
        ],
        'implementedFinders' => [
            'slugged' => 'findSlugged',
        ],
        'implementedMethods' => [
            'slug' => 'slug',
        ],
    ];

    /**
     * Constructor.
     *
     * @param \Cake\ORM\Table $table The table this behavior is attached to.
     * @param array $config The config for this behavior.
     */
    public function __construct(Table $table, array $config = [])
    {
        if (!empty($config['implementedEvents'])) {
            $this->_defaultConfig['implementedEvents'] = $config['implementedEvents'];
            unset($config['implementedEvents']);
        }
        parent::__construct($table, $config);
    }

    /**
     * Initialize behavior
     *
     * @param array $config The configuration settings provided to this behavior.
     * @return void
     */
    public function initialize(array $config)
    {
        $slugger = $this->config('slugger');

        if (is_string($slugger)) {
            if (!class_exists($slugger)) {
                throw new UnexpectedValueException('Missing the `' . $slugger . '` slugger class.');
            }
        }

        if (!class_implements($slugger, 'Muffin\Slug\SluggerInterface')) {
            throw new UnexpectedValueException('Slugger must implement `Muffin\Slug\SluggerInterface`.');
        }

        if (!$this->config('displayField')) {
            $this->config('displayField', $this->_table->displayField());
        }

        if ($this->config('maxLength') === null) {
            $this->config('maxLength', $this->_table->schema()->column($this->config('field'))['length']);
        }

        if ($this->config('unique') === true) {
            $this->config('unique', [$this, '_uniqueSlug']);
        }
    }

    /**
     * Returns list of event this behavior is interested in.
     *
     * @return array
     */
    public function implementedEvents()
    {
        return $this->config('implementedEvents');
    }

    /**
     * Callback for Model.buildValidator event.
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired.
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @param string $name Validator name.
     * @return void
     */
    public function buildValidator(Event $event, Validator $validator, $name)
    {
        foreach ((array)$this->config('displayField') as $field) {
            $validator->requirePresence($field, 'create')
                ->notEmpty($field);
        }
    }

    /**
     * Callback for Model.beforeSave event.
     *
     * @param \Cake\Event\Event $event The afterSave event that was fired.
     * @param \Cake\ORM\Entity $entity The entity that was saved.
     * @param \ArrayObject $options Options.
     * @return void
     */
    public function beforeSave(Event $event, Entity $entity, ArrayObject $options)
    {
        $slugField = $this->config('field');
        $fields = (array)$this->config('displayField');
        $separator = $this->config('separator');

        if (!$entity->isNew() || $entity->dirty($slugField)) {
            return;
        }

        $parts = [];
        foreach ($fields as $field) {
            if ($entity->errors($field)) {
                return;
            }
            $parts[] = $entity->{$field};
        }

        $entity->set($slugField, $this->slug($entity, implode($separator, $parts), $separator));
    }

    /**
     * Custom finder.
     *
     * @param \Cake\ORM\Query $query Query.
     * @param array $options Options.
     * @return \Cake\ORM\Query Query.
     */
    public function findSlugged(Query $query, array $options)
    {
        if (empty($options['slug'])) {
            throw new InvalidArgumentException('The `slug` key is required by the `slugged` finder.');
        }

        return $query->where([$this->config('field') => $options['slug']]);
    }

    /**
     * Generates slug.
     *
     * @param \Cake\ORM\Entity|string $entity Entity to create slug for
     * @param string $string String to create slug for.
     * @param string $separator Separator.
     * @return string Slug.
     */
    public function slug($entity, $string = null, $separator = '-')
    {
        if (is_string($entity)) {
            if ($string !== null) {
                $separator = $string;
            }
            $string = $entity;
            unset($entity);
        } elseif (($entity instanceof Entity) && $string === null) {
            $string = [];
            foreach ((array)$this->config('displayField') as $field) {
                if ($entity->errors($field)) {
                    throw new InvalidArgumentException();
                }
                $string[] = $entity->get($field);
            }
            $string = implode($separator, $string);
        }

        $slug = $this->_slug($string, $separator);

        if (isset($entity) && $unique = $this->config('unique')) {
            $slug = $unique($entity, $slug, $separator);
        }

        return $slug;
    }

    /**
     * Returns a unique slug.
     *
     * @param \Cake\ORM\Entity $entity Entity.
     * @param string $slug Slug.
     * @param string $separator Separator.
     * @return string Unique slug.
     */
    protected function _uniqueSlug(Entity $entity, $slug, $separator = '-')
    {
        $primaryKey = $this->_table->primaryKey();

        $conditions = [$this->_table->aliasField($this->config('field')) => $slug];
        $conditions += $this->config('scope');
        if ($id = $entity->{$primaryKey}) {
            $conditions['NOT'][$this->_table->aliasField($primaryKey)] = $id;
        }

        $i = 0;
        $suffix = '';
        $field = $this->config('field');
        $length = $this->config('length');

        while ($this->_table->exists($conditions)) {
            $i++;
            $suffix = $separator . $i;
            if ($length && $length < mb_strlen($slug . $suffix)) {
                $slug = mb_substr($slug, 0, $length - mb_strlen($suffix));
            }
            $conditions[$field] = $slug . $suffix;
        }

        return $slug . $suffix;
    }

    /**
     * Proxies the defined slugger's `slug` method.
     *
     * @param string $string String to create a slug from.
     * @param  string $separator String to use as separator/separator.
     * @return string Slug.
     */
    protected function _slug($string, $separator)
    {
        $replacements = $this->config('replacements');
        $func = [$this->config('slugger'), 'slug'];
        return $func(str_replace(array_keys($replacements), $replacements, $string), $separator);
    }
}

# yii2-relation-trait

> **Note**: This is **not** the official extension by [@mootensai](https://github.com/mootensai).  
> I am not the creator of the original extension. I have made bug fixes and improvements that suit my use case.  
> Feel free to use it or refer to the official package
> at [mootensai/yii2-relation-trait](https://github.com/mootensai/yii2-relation-trait).

Yii 2 Models add functionality for loading related models via `loadAll($POST)` and transactional saving via
`saveAll()`.  
It also supports **soft delete** and **soft restore** features.

Works best with [mootensai/yii2-enhanced-gii](https://github.com/mootensai/yii2-enhanced-gii).

## Badges

[![Latest Stable Version](https://poser.pugx.org/deadmantfa/yii2-relation-trait/v/stable)](https://packagist.org/packages/deadmantfa/yii2-relation-trait)
[![License](https://poser.pugx.org/deadmantfa/yii2-relation-trait/license)](https://packagist.org/packages/deadmantfa/yii2-relation-trait)
[![Total Downloads](https://img.shields.io/packagist/dt/deadmantfa/yii2-relation-trait.svg?style=flat-square)](https://packagist.org/packages/deadmantfa/yii2-relation-trait)
[![Monthly Downloads](https://poser.pugx.org/deadmantfa/yii2-relation-trait/d/monthly)](https://packagist.org/packages/deadmantfa/yii2-relation-trait)
[![Daily Downloads](https://poser.pugx.org/deadmantfa/yii2-relation-trait/d/daily)](https://packagist.org/packages/deadmantfa/yii2-relation-trait)

## Installation

The preferred way to install this extension is through [Composer](http://getcomposer.org/download/).

Either run

```bash
composer require deadmantfa/yii2-relation-trait
```

or add

```php
"deadmantfa/yii2-relation-trait": "^2.0.0"
```

to the require section of your application's ```composer.json``` file.

## Usage in the Model

```php
use deadmantfa\relation\RelationTrait;

class MyModel extends \yii\db\ActiveRecord
{
    use RelationTrait;

    // ...
}
```

## Controller Usage

The extension expects a **normal array of POST** data. For example:

```php
[
    $_POST['ParentClass'] => [
        'attr1' => 'value1',
        'attr2' => 'value2',
        // Has many
        'relationName' => [
            [ 'relAttr' => 'relValue1' ],
            [ 'relAttr' => 'relValue2' ]
        ],
        // Has one
        'relationName' => [
            'relAttr1' => 'relValue1',
            'relAttr2' => 'relValue2'
        ]
    ]
];
```

In your controller:

```php
$model = new ParentClass();
if ($model->loadAll(Yii::$app->request->post()) && $model->saveAll()) {
    return $this->redirect(['view', 'id' => $model->id]);
}
```

Features

1. Transaction Support
   a. Your data changes are atomic (ACID compliant).
2. Normal ```save()```
   a. Behaviors still work as usual since it’s built on top of Yii’s ```ActiveRecord```.
3. Validation
   a. Errors from related models appear via ```errorSummary()```, e.g.
    ```text
        MyRelatedClass #2: [Error message]
    ```
4. UUID or Auto-Increment
   Works with any PK strategy,
   including [mootensai/yii2-uuid-behavior](https://github.com/mootensai/yii2-uuid-behavior).
5. Soft Delete
   By defining ```$_rt_softdelete``` in your model constructor (and ```$_rt_softrestore``` for restoring), you can
   softly mark rows as deleted instead of physically removing them.
   ```php
        private $_rt_softdelete;
        private $_rt_softrestore;
    
        public function __construct($config = [])
        {
            parent::__construct($config);
    
            $this->_rt_softdelete = [
                'is_deleted' => 1,
                'deleted_by' => Yii::$app->user->id,
                'deleted_at' => date('Y-m-d H:i:s'),
            ];
    
            $this->_rt_softrestore = [
                'is_deleted' => 0,
                'deleted_by' => null,
                'deleted_at' => null,
            ];
        }
    ```

## Array Outputs

```php
print_r($model->getAttributesWithRelatedAsPost());
```

Produces a POST-like structure with the main model and related arrays.

```php
print_r($model->getAttributesWithRelated());
```

Produces a nested structure under ```[relationName] => [...]```.

## Contributing or Reporting Issues

Please open an [issue](https://github.com/deadmantfa/yii2-relation-trait/pulls) or submit a PR if you find a bug or have
an improvement idea.

---

### Disclaimer

This package is a **fork** or an alternative
to [mootensai/yii2-relation-trait](https://github.com/mootensai/yii2-relation-trait).
**All credit** to [@mootensai](https://github.com/mootensai) for the initial code.
**This is not meant to replace** the original package but rather provide bug fixes and enhancements under a different
namespace.
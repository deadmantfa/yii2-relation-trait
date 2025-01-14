<?php
declare(strict_types=1);

namespace deadmantfa\relation;

use ReflectionClass;
use Throwable;
use Yii;
use yii\base\Model;
use yii\db\ActiveQuery;
use yii\db\ActiveQueryInterface;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\db\IntegrityException;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;
use yii\i18n\PhpMessageSource;

/**
 * Trait RelationTrait
 *
 * Provides methods for mass-loading related models via POST data and saving/deleting
 * them in a single transaction. Includes optional soft-delete/restore functionality.
 */
trait RelationTrait
{
    /**
     * Load model attributes including related attributes from POST data.
     *
     * @param array $POST The POST data (likely Yii::$app->request->post()).
     * @param array $skippedRelations Relations to exclude from load.
     */
    public function loadAll(array $POST, array $skippedRelations = []): bool
    {
        if (!$this->load($POST)) {
            return false;
        }

        $shortName = StringHelper::basename(static::class);
        $relData = $this->getRelationData();

        // We assume the top-level array in $POST is named after $shortName or the related classes
        foreach ($POST as $model => $attr) {
            if (!is_array($attr)) {
                continue;
            }

            // If the POST key matches our model short name
            if ($model === $shortName) {
                // e.g. $POST['MyModel'] => ['someRelation' => [...], 'anotherRelation' => [...]]
                foreach ($attr as $relName => $relAttr) {
                    if (!is_array($relAttr)) {
                        continue;
                    }
                    $isHasMany = !ArrayHelper::isAssociative($relAttr);
                    if (in_array($relName, $skippedRelations, true) || !array_key_exists($relName, $relData)) {
                        continue;
                    }
                    $this->loadToRelation($isHasMany, $relName, $relAttr);
                }
            } else {
                // If $model is the name of a related class, guess that the relation name
                // is pluralized or singularized version of $model.
                $isHasMany = is_array(current($attr));
                $relName = $isHasMany
                    ? lcfirst(Inflector::pluralize($model))
                    : lcfirst($model);

                if (in_array($relName, $skippedRelations, true) || !array_key_exists($relName, $relData)) {
                    continue;
                }
                $this->loadToRelation($isHasMany, $relName, $attr);
            }
        }

        return true;
    }

    /**
     * Retrieves information about all relations defined in this model by scanning:
     *  - 'relationNames()' method if defined in the model (must return an array of relation names as strings), or
     *  - reflection for 'getXYZ()' methods returning an ActiveQueryInterface.
     */
    public function getRelationData(): array
    {
        $stack = [];

        // If the model has a custom "relationNames()" method, use that to retrieve relation names
        if (method_exists($this, 'relationNames')) {
            // Expecting something like: ['relationA', 'relationB', ...]
            $names = $this->relationNames();
            foreach ($names as $name) {
                /** @var ActiveQuery $rel */
                $rel = $this->getRelation($name);
                $stack[$name] = [
                    'name' => $name,
                    'method' => 'get' . ucfirst($name),
                    'ismultiple' => $rel->multiple,
                    'modelClass' => $rel->modelClass,
                    'link' => $rel->link,
                    'via' => $rel->via,
                ];
            }
            return $stack;
        }

        // Otherwise, reflect on all getSomething() methods
        $ARMethods = get_class_methods(ActiveRecord::class);
        $modelMethods = get_class_methods(Model::class);
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getMethods() as $method) {
            $methodName = $method->getName();

            // Skip parent or irrelevant methods
            if (in_array($methodName, $ARMethods, true) ||
                in_array($methodName, $modelMethods, true) ||
                in_array($methodName, [
                    'getRelationData',
                    'getAttributesWithRelatedAsPost',
                    'getAttributesWithRelated',
                    'getRelatedRecordsTree',
                ], true)
            ) {
                continue;
            }

            if (strpos($methodName, 'get') !== 0) {
                continue;
            }
            if ($method->getNumberOfParameters() > 0) {
                continue;
            }

            try {
                $rel = call_user_func([$this, $methodName]);
                if (!$rel instanceof ActiveQueryInterface) {
                    continue;
                }
                $propName = lcfirst(preg_replace('/^get/', '', $methodName));
                $stack[$propName] = [
                    'name' => $propName,
                    'method' => $methodName,
                    'ismultiple' => $rel->multiple,
                    'modelClass' => $rel->modelClass,
                    'link' => $rel->link,
                    'via' => $rel->via,
                ];
            } catch (Throwable $exc) {
                // ignore
            }
        }

        return $stack;
    }

    /**
     * Load array data into a single relation (HasOne, HasMany, or ManyMany).
     *
     * @param bool $isHasMany Whether this relation is plural (HasMany / ManyMany).
     * @param string $relName The relation name in the AR model.
     * @param array $v The data array for that relation.
     */
    private function loadToRelation(bool $isHasMany, string $relName, array $v): bool
    {
        /** @var ActiveRecord $this */
        $AQ = $this->getRelation($relName);
        $relModelClass = $AQ->modelClass;
        $relPKAttr = $relModelClass::primaryKey();
        // If there's more than one column in the PK array, we consider it ManyMany.
        // (In some advanced pivot scenarios, you might refine this logic further.)
        $isManyMany = (count($relPKAttr) > 1);

        // Many-to-many
        if ($isManyMany) {
            $container = [];
            foreach ($v as $relPost) {
                if (!array_filter($relPost)) {
                    continue;
                }
                // Build condition for the pivot table
                // Make sure $relPKAttr[0] exists
                if (!isset($relPKAttr[0])) {
                    // No well-defined first PK attribute => skip or handle otherwise
                    continue;
                }
                $condition = [$relPKAttr[0] => $this->primaryKey];

                foreach ($relPost as $relAttr => $relAttrVal) {
                    if (in_array($relAttr, $relPKAttr, true)) {
                        $condition[$relAttr] = $relAttrVal;
                    }
                }

                $relObj = $relModelClass::findOne($condition);
                if ($relObj === null) {
                    $relObj = new $relModelClass();
                }
                $relObj->load($relPost, '');
                $container[] = $relObj;
            }
            $this->populateRelation($relName, $container);
            return true;
        }

        // HasMany
        if ($isHasMany) {
            $container = [];
            foreach ($v as $relPost) {
                if (!array_filter($relPost)) {
                    continue;
                }
                $primaryKeyVal = $relPost[$relPKAttr[0]] ?? null;
                $relObj = null;
                if ($primaryKeyVal) {
                    $relObj = $relModelClass::findOne($primaryKeyVal);
                }
                if ($relObj === null) {
                    $relObj = new $relModelClass();
                }
                $relObj->load($relPost, '');
                $container[] = $relObj;
            }
            $this->populateRelation($relName, $container);
            return true;
        }

        // HasOne
        $primaryKeyVal = $v[$relPKAttr[0]] ?? null;
        $relObj = null;
        if ($primaryKeyVal) {
            $relObj = $relModelClass::findOne($primaryKeyVal);
        }
        if ($relObj === null) {
            $relObj = new $relModelClass();
        }
        $relObj->load($v, '');
        $this->populateRelation($relName, $relObj);

        return true;
    }

    /**
     * Save model and all related records in a transaction.
     * Optionally uses soft-delete if configured.
     *
     * @param array $skippedRelations Relations to exclude from save.
     * @throws Exception
     */
    public function saveAll(array $skippedRelations = []): bool
    {
        /** @var ActiveRecord $this */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        $isNewRecord = $this->isNewRecord;
        $isSoftDelete = isset($this->_rt_softdelete);
        $error = false;

        try {
            if (!$this->save()) {
                $trans->rollBack();
                return false;
            }

            // Save any loaded related records
            if (!empty($this->relatedRecords)) {
                foreach ($this->relatedRecords as $name => $records) {
                    if (in_array($name, $skippedRelations, true)) {
                        continue;
                    }

                    /** @var ActiveQuery $AQ */
                    $AQ = $this->getRelation($name);
                    $link = $AQ->link;
                    if (empty($records)) {
                        continue;
                    }

                    if ($AQ->multiple) {
                        // ManyMany or HasMany
                        $firstRelModel = is_array($records) ? reset($records) : null;
                        if (!$firstRelModel instanceof ActiveRecord) {
                            continue;
                        }
                        $relPKAttr = $firstRelModel->primaryKey();
                        $isManyMany = (count($relPKAttr) > 1);
                        // Collect PK & FK for leftover deletion
                        $notDeletedPK = [];
                        $notDeletedFK = [];
                        foreach ($records as $index => $relModel) {
                            if (!$relModel instanceof ActiveRecord) {
                                continue;
                            }
                            // Ensure this child's FK references the parent
                            foreach ($link as $key => $value) {
                                $relModel->{$key} = $this->{$value};
                                $notDeletedFK[$key] = $this->{$value};
                            }

                            // Mark PK for not-deleting
                            if ($isManyMany) {
                                $mainPK = array_key_first($link);
                                // In a truly multi-column pivot, you might need more robust logic.
                                foreach ($relModel->primaryKey as $attr => $val) {
                                    if ($attr !== $mainPK) {
                                        $notDeletedPK[$attr][] = $val;
                                    }
                                }
                            } else {
                                $notDeletedPK[] = $relModel->primaryKey;
                            }
                        }
                        // For existing parent, remove leftover children
                        if (!$isNewRecord) {
                            $relationLabel = Yii::t('app', Inflector::camel2words(StringHelper::basename($AQ->modelClass)));
                            $relModel = $firstRelModel;

                            if ($isManyMany) {
                                // ManyMany leftover cleanup
                                $query = ['and', $notDeletedFK];
                                foreach ($notDeletedPK as $attr => $values) {
                                    $query[] = ['not in', $attr, $values];
                                }
                                $this->safeDeleteOrSoftDelete(
                                    $relModel,
                                    $isSoftDelete ? $this->_rt_softdelete : [],
                                    $query,
                                    $relationLabel,
                                    $name,
                                    $error
                                );
                            } else {
                                // HasMany leftover cleanup
                                $primaryColumn = $relPKAttr[0] ?? null;
                                if ($primaryColumn !== null && !empty($notDeletedPK)) {
                                    $query = ['and', $notDeletedFK, ['not in', $primaryColumn, $notDeletedPK]];
                                    $this->safeDeleteOrSoftDelete(
                                        $relModel,
                                        $isSoftDelete ? $this->_rt_softdelete : [],
                                        $query,
                                        $relationLabel,
                                        $name,
                                        $error
                                    );
                                }
                            }
                        }
                        // Now save each related record
                        foreach ($records as $index => $relModel) {
                            if (!$relModel->save() || !empty($relModel->errors)) {
                                $relModelWords = Yii::t('app', Inflector::camel2words(StringHelper::basename($AQ->modelClass)));
                                $idx = $index + 1;
                                foreach ($relModel->errors as $validation) {
                                    foreach ($validation as $errorMsg) {
                                        $this->addError($name, "$relModelWords #$idx : $errorMsg");
                                    }
                                }
                                $error = true;
                            }
                        }
                    } elseif (!is_array($records) && $records instanceof ActiveRecord) {
                        // HasOne
                        $relModel = $records;
                        foreach ($link as $key => $value) {
                            $relModel->{$key} = $this->{$value};
                        }
                        if (!$relModel->save() || !empty($relModel->errors)) {
                            $recordsWords = Yii::t('app', Inflector::camel2words(StringHelper::basename($AQ->modelClass)));
                            foreach ($relModel->errors as $validation) {
                                foreach ($validation as $errorMsg) {
                                    $this->addError($name, "$recordsWords : $errorMsg");
                                }
                            }
                            $error = true;
                        }
                    }
                }
            }

            // Remove children for relations not in $this->relatedRecords
            $relAvail = array_keys($this->relatedRecords);
            $relData = $this->getRelationData();
            $allRel = array_keys($relData);
            $noChildren = array_diff($allRel, $relAvail);

            foreach ($noChildren as $relName) {
                if (!empty($relData[$relName]['via']) ||
                    in_array($relName, $skippedRelations, true)
                ) {
                    continue;
                }

                $relModelClass = $relData[$relName]['modelClass'];
                /** @var ActiveRecord $relModel */
                $relModel = new $relModelClass();
                $condition = $this->buildConditionFromLink($relData[$relName]['link']);

                $relationLabel = Inflector::camel2words(StringHelper::basename($relData[$relName]['modelClass']));
                $this->safeDeleteOrSoftDelete(
                    $relModel,
                    $isSoftDelete ? $this->_rt_softdelete : [],
                    ['and', $condition],
                    $relationLabel,
                    $relData[$relName]['name'],
                    $error
                );
            }

            if ($error) {
                $trans->rollBack();
                $this->isNewRecord = $isNewRecord;
                return false;
            }

            $trans->commit();
            return true;
        } catch (Exception $exc) {
            $trans->rollBack();
            $this->isNewRecord = $isNewRecord;
            throw $exc;
        }
    }

    /**
     * Unified method to either "soft-delete" (updateAll) or physically delete (deleteAll),
     * catching IntegrityException for foreign key constraints.
     *
     * @param ActiveRecord $modelClassOrInstance Model class or an instance used for static calls.
     * @param array $softDeleteData The soft-delete attributes (if any). If empty, do hard delete.
     * @param array $condition The query condition (e.g. ['and', [...]]).
     * @param string $relationLabel Label for error messages.
     * @param string $relationName The relation property name (for $this->addError).
     * @param bool $errorRef This is passed by reference—set to true if an IntegrityException occurs.
     */
    private function safeDeleteOrSoftDelete(
        ActiveRecord $modelClassOrInstance,
        array        $softDeleteData,
        array        $condition,
        string       $relationLabel,
        string       $relationName,
        bool         &$errorRef
    ): void
    {
        try {
            if (!empty($softDeleteData)) {
                $modelClassOrInstance->updateAll($softDeleteData, $condition);
            } else {
                $modelClassOrInstance->deleteAll($condition);
            }
        } catch (IntegrityException $exc) {
            // Optionally append the DB error message for debugging
            $dbMsg = $exc->getMessage();
            $errorMsg = Yii::t('app', "Data can't be deleted because it's still used by another data. DB says: {dbErr}", [
                'dbErr' => $dbMsg,
            ]);
            $this->addError($relationName, "$relationLabel: $errorMsg");
            $errorRef = true;
        }
    }

    /**
     * A small helper for building a 'where' condition array from a relation link array.
     *
     * For example, if $link = ['foreign_key_id' => 'id'], we build `['foreign_key_id' => $this->id]`.
     */
    private function buildConditionFromLink(array $link): array
    {
        $condition = [];
        foreach ($link as $key => $value) {
            if (isset($this->{$value})) {
                $condition[$key] = $this->{$value};
            }
        }
        return $condition;
    }

    /**
     * Delete this model and all related records.
     * If soft-delete is configured, it will only mark them as deleted.
     *
     * @param array $skippedRelations Relations to exclude from deletion.
     * @throws Exception|Throwable
     */
    public function deleteWithRelated(array $skippedRelations = []): bool
    {
        /** @var ActiveRecord $this */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        $isSoftDelete = isset($this->_rt_softdelete);
        $error = false;

        try {
            $relData = $this->getRelationData();
            foreach ($relData as $data) {
                if (!$data['ismultiple'] || in_array($data['name'], $skippedRelations, true)) {
                    continue;
                }
                $relationName = $data['name'];

                // If the relation is empty, no need to do anything
                if (empty($this->{$relationName}) || !is_array($this->{$relationName})) {
                    continue;
                }

                // Build condition
                $condition = $this->buildConditionFromLink($data['link']);
                if (empty($condition)) {
                    continue;
                }

                /** @var ActiveRecord|bool $firstChild */
                $firstChild = reset($this->{$relationName});
                // If $firstChild is `false`, it might mean an empty array or non-ActiveRecord entries
                if (!$firstChild instanceof ActiveRecord) {
                    continue;
                }

                $relModelClass = get_class($firstChild);

                try {
                    if ($isSoftDelete) {
                        $relModelClass::updateAll($this->_rt_softdelete, ['and', $condition]);
                    } else {
                        $relModelClass::deleteAll(['and', $condition]);
                    }
                } catch (IntegrityException $exc) {
                    // Optionally include the actual DB exception message:
                    $message = Yii::t('app', "Cannot delete related data due to foreign key constraints. DB says: {error}", [
                        'error' => $exc->getMessage(),
                    ]);
                    $this->addError($relationName, $message);
                    $error = true;
                    break;
                }
            }

            if ($error) {
                $trans->rollBack();
                return false;
            }

            // Finally handle the parent
            if ($isSoftDelete) {
                $this->attributes = array_merge($this->attributes, $this->_rt_softdelete);
                if ($this->save(false)) {
                    $trans->commit();
                    return true;
                }
                $trans->rollBack();
                return false;
            }

            if ($this->delete()) {
                $trans->commit();
                return true;
            }
            $trans->rollBack();
            return false;
        } catch (Exception $exc) {
            $trans->rollBack();
            throw $exc;
        }
    }

    /**
     * Restore a soft-deleted row, including all related records.
     * Requires $_rt_softrestore to be defined in the model.
     *
     * @param array $skippedRelations Relations to exclude from restore.
     * @throws Exception
     */
    public function restoreWithRelated(array $skippedRelations = []): bool
    {
        if (!isset($this->_rt_softrestore)) {
            return false;
        }

        /** @var ActiveRecord $this */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        $error = false;

        try {
            $relData = $this->getRelationData();
            foreach ($relData as $data) {
                if (!$data['ismultiple'] || in_array($data['name'], $skippedRelations, true)) {
                    continue;
                }

                $relationName = $data['name'];
                if (empty($this->{$relationName}) || !is_array($this->{$relationName})) {
                    continue;
                }

                $condition = $this->buildConditionFromLink($data['link']);
                if (empty($condition)) {
                    continue;
                }

                /** @var ActiveRecord|bool $firstChild */
                $firstChild = reset($this->{$relationName});
                if (!$firstChild instanceof ActiveRecord) {
                    // if it's not a valid AR instance, skip
                    continue;
                }

                $relModelClass = get_class($firstChild);

                try {
                    $relModelClass::updateAll($this->_rt_softrestore, ['and', $condition]);
                } catch (IntegrityException $exc) {
                    $message = Yii::t('app', "Cannot restore related data due to foreign key constraints. DB says: {error}", [
                        'error' => $exc->getMessage(),
                    ]);
                    $this->addError($relationName, $message);
                    $error = true;
                    break;
                }
            }

            if ($error) {
                $trans->rollBack();
                return false;
            }

            // Restore the parent
            $this->attributes = array_merge($this->attributes, $this->_rt_softrestore);
            if ($this->save(false)) {
                $trans->commit();
                return true;
            }

            $trans->rollBack();
            return false;
        } catch (Exception $exc) {
            $trans->rollBack();
            throw $exc;
        }
    }

    /**
     * Deprecated: Return array structured for form POST data (the "multi-dimensional" approach).
     */
    public function getAttributesWithRelatedAsPost(): array
    {
        $shortName = StringHelper::basename(static::class);
        $return = [
            $shortName => $this->attributes,
        ];
        return $this->getRelatedRecordsTree($return);
    }

    /**
     * Builds a nested array representation of the current model's related records.
     */
    public function getRelatedRecordsTree(array $return): array
    {
        foreach ($this->relatedRecords as $name => $records) {
            /** @var ActiveQuery $AQ */
            $AQ = $this->getRelation($name);
            if ($AQ->multiple) {
                foreach ($records as $index => $record) {
                    $return[$name][$index] = $record->attributes;
                }
            } else {
                $return[$name] = $records->attributes;
            }
        }
        return $return;
    }

    /**
     * Return array of attributes including related records in a single tree.
     */
    public function getAttributesWithRelated(): array
    {
        /** @var ActiveRecord $this */
        $return = $this->attributes;
        return $this->getRelatedRecordsTree($return);
    }

    /**
     * Initialize i18n configuration for message translation.
     *
     * @throws \Exception
     */
    public function initI18N(): void
    {
        $reflector = new ReflectionClass(static::class);
        $dir = dirname($reflector->getFileName());

        Yii::setAlias('@mtrelt', $dir);

        $config = [
            'class' => PhpMessageSource::class,
            'basePath' => '@mtrelt/messages',
            'forceTranslation' => true,
        ];
        $globalConfig = ArrayHelper::getValue(Yii::$app->i18n->translations, 'mtrelt*', []);

        if (!empty($globalConfig)) {
            $config = array_merge($config, is_array($globalConfig) ? $globalConfig : (array)$globalConfig);
        }
        Yii::$app->i18n->translations['mtrelt*'] = $config;
    }
}

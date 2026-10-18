<?php

namespace Entities;

/**
 * Method-only reuse for the scalar preference fields mapped by each concrete
 * preference entity.
 *
 * @property ?string $attribute
 * @property ?int $ix
 * @property ?string $op
 * @property ?string $value
 * @property ?int $expire
 */
trait PreferenceScalarAccessors
{
    /**
     * @param string $attribute
     * @return $this
     */
    public function setAttribute($attribute)
    {
        $this->attribute = $attribute;

        return $this;
    }

    /** @return string|null */
    public function getAttribute()
    {
        return $this->attribute;
    }

    /**
     * @param integer $ix
     * @return $this
     */
    public function setIx($ix)
    {
        $this->ix = $ix;

        return $this;
    }

    /** @return integer|null */
    public function getIx()
    {
        return $this->ix;
    }

    /**
     * @param string $op
     * @return $this
     */
    public function setOp($op)
    {
        $this->op = $op;

        return $this;
    }

    /** @return string|null */
    public function getOp()
    {
        return $this->op;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /** @return string|null */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param integer $expire
     * @return $this
     */
    public function setExpire($expire)
    {
        $this->expire = $expire;

        return $this;
    }

    /** @return integer|null */
    public function getExpire()
    {
        return $this->expire;
    }
}

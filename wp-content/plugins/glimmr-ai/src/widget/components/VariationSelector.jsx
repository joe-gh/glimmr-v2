/**
 * VariationSelector - Product Variation Selection Component
 *
 * Renders attribute selectors (color swatches, size dropdowns, etc.)
 * for variable products.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useState, useEffect, useCallback } from 'preact/hooks';

/**
 * Color swatch component for color-type attributes.
 */
const ColorSwatch = ({ name, value, color, isSelected, onClick, disabled }) => {
    // Try to determine color from value name or use a default
    const getSwatchColor = () => {
        if (color) return color;

        // Common color name mappings
        const colorMap = {
            'black': '#000000',
            'white': '#FFFFFF',
            'red': '#EF4444',
            'blue': '#3B82F6',
            'green': '#10B981',
            'yellow': '#FBBF24',
            'orange': '#F97316',
            'purple': '#8B5CF6',
            'pink': '#EC4899',
            'gray': '#6B7280',
            'grey': '#6B7280',
            'brown': '#92400E',
            'navy': '#1E3A8A',
            'beige': '#D4C5B9',
            'cream': '#FFFDD0',
            'gold': '#D4AF37',
            'silver': '#C0C0C0',
        };

        const lowerValue = value.toLowerCase();
        return colorMap[lowerValue] || '#9CA3AF';
    };

    return (
        <button
            type="button"
            className={`glimmr-swatch ${isSelected ? 'is-selected' : ''} ${disabled ? 'is-disabled' : ''}`}
            onClick={() => !disabled && onClick(name, value)}
            disabled={disabled}
            aria-label={`Select ${value}`}
            aria-pressed={isSelected}
            title={value}
        >
            <span
                className="glimmr-swatch-color"
                style={{ backgroundColor: getSwatchColor() }}
            />
            {isSelected && (
                <svg className="glimmr-swatch-check" viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                </svg>
            )}
        </button>
    );
};

/**
 * Button group for non-color attributes like size.
 */
const ButtonGroup = ({ name, options, selected, onChange, disabled, disabledOptions = [] }) => (
    <div className="glimmr-button-group" role="radiogroup" aria-label={name}>
        {options.map((option) => {
            const isDisabled = disabled || disabledOptions.includes(option);
            return (
                <button
                    key={option}
                    type="button"
                    className={`glimmr-button-option ${selected === option ? 'is-selected' : ''} ${isDisabled ? 'is-disabled' : ''}`}
                    onClick={() => !isDisabled && onChange(name, option)}
                    disabled={isDisabled}
                    role="radio"
                    aria-checked={selected === option}
                >
                    {option}
                </button>
            );
        })}
    </div>
);

/**
 * Dropdown for attributes with many options.
 */
const Dropdown = ({ name, options, selected, onChange, disabled, disabledOptions = [] }) => (
    <select
        className="glimmr-select"
        value={selected || ''}
        onChange={(e) => onChange(name, e.target.value)}
        disabled={disabled}
        aria-label={name}
    >
        <option value="">Select {name}</option>
        {options.map((option) => (
            <option
                key={option}
                value={option}
                disabled={disabledOptions.includes(option)}
            >
                {option}
            </option>
        ))}
    </select>
);

/**
 * Main VariationSelector component.
 */
const VariationSelector = ({
    attributes,
    variations,
    selectedAttributes = {},
    onChange,
    disabled = false,
}) => {
    const [selected, setSelected] = useState(selectedAttributes);
    const [availableOptions, setAvailableOptions] = useState({});

    /**
     * Determine which options are available based on current selections.
     */
    useEffect(() => {
        if (!variations || variations.length === 0) {
            // No variations - all options available
            const allOptions = {};
            Object.entries(attributes).forEach(([name, rawOpts]) => {
                allOptions[name] = Array.isArray(rawOpts)
                    ? rawOpts
                    : (typeof rawOpts === 'string'
                        ? rawOpts.split(',').map(s => s.trim()).filter(Boolean)
                        : []);
            });
            setAvailableOptions(allOptions);
            return;
        }

        // Filter variations based on current selections
        const available = {};

        Object.entries(attributes).forEach(([attrName, rawAttrOptions]) => {
            // Normalize to array (same as render path — API data may
            // arrive as a comma-separated string before Store API
            // replaces it).
            const attrOptions = Array.isArray(rawAttrOptions)
                ? rawAttrOptions
                : (typeof rawAttrOptions === 'string'
                    ? rawAttrOptions.split(',').map(s => s.trim()).filter(Boolean)
                    : []);

            // For each attribute, find which options are available
            // based on the current selection of OTHER attributes
            const validOptions = attrOptions.filter((option) => {
                // Check if any variation has this option AND matches other selections
                return variations.some((variation) => {
                    // Variation must have this option
                    if (variation.attributes[attrName] !== option && variation.attributes[attrName] !== '') {
                        return false;
                    }

                    // Variation must match all other selected attributes
                    return Object.entries(selected).every(([selName, selValue]) => {
                        if (selName === attrName || !selValue) return true;
                        return (
                            variation.attributes[selName] === selValue ||
                            variation.attributes[selName] === ''
                        );
                    });
                });
            });

            available[attrName] = validOptions;
        });

        setAvailableOptions(available);
    }, [attributes, variations, selected]);

    /**
     * Handle attribute selection change.
     */
    const handleChange = useCallback((name, value) => {
        const newSelected = { ...selected, [name]: value };
        setSelected(newSelected);

        if (onChange) {
            // Find matching variation if all attributes are selected
            const allSelected = Object.entries(attributes).every(
                ([attrName]) => newSelected[attrName]
            );

            let matchedVariation = null;
            if (allSelected && variations) {
                matchedVariation = variations.find((v) =>
                    Object.entries(newSelected).every(
                        ([attrName, attrValue]) =>
                            v.attributes[attrName] === attrValue ||
                            v.attributes[attrName] === ''
                    )
                );
            }

            onChange(newSelected, matchedVariation);
        }
    }, [selected, attributes, variations, onChange]);

    /**
     * Determine the best UI type for an attribute.
     */
    const getAttributeType = (name, options) => {
        const lowerName = name.toLowerCase();

        // Color-type attributes
        if (lowerName.includes('color') || lowerName.includes('colour')) {
            return 'color';
        }

        // Size-type attributes with few options - use buttons
        if (
            (lowerName.includes('size') || lowerName === 's' || lowerName === 'm' || lowerName === 'l') &&
            options.length <= 6
        ) {
            return 'buttons';
        }

        // Few options - use buttons
        if (options.length <= 5) {
            return 'buttons';
        }

        // Many options - use dropdown
        return 'dropdown';
    };

    if (!attributes || Object.keys(attributes).length === 0) {
        return null;
    }

    return (
        <div className="glimmr-variation-selector">
            {Object.entries(attributes).map(([name, rawOptions]) => {
                // Normalize options to array — API data may arrive as a
                // comma-separated string or other non-array format before
                // the Store API transform replaces it.
                const options = Array.isArray(rawOptions)
                    ? rawOptions
                    : (typeof rawOptions === 'string'
                        ? rawOptions.split(',').map(s => s.trim()).filter(Boolean)
                        : []);

                if (options.length === 0) return null;

                const type = getAttributeType(name, options);
                const disabledOptions = options.filter(
                    (opt) => !(availableOptions[name] || []).includes(opt)
                );

                return (
                    <div key={name} className="glimmr-variation-attribute">
                        <label className="glimmr-variation-label">
                            {name}
                            {selected[name] && (
                                <span className="glimmr-variation-selected">: {selected[name]}</span>
                            )}
                        </label>

                        {type === 'color' && (
                            <div className="glimmr-swatch-group">
                                {options.map((option) => (
                                    <ColorSwatch
                                        key={option}
                                        name={name}
                                        value={option}
                                        isSelected={selected[name] === option}
                                        onClick={handleChange}
                                        disabled={disabled || disabledOptions.includes(option)}
                                    />
                                ))}
                            </div>
                        )}

                        {type === 'buttons' && (
                            <ButtonGroup
                                name={name}
                                options={options}
                                selected={selected[name]}
                                onChange={handleChange}
                                disabled={disabled}
                                disabledOptions={disabledOptions}
                            />
                        )}

                        {type === 'dropdown' && (
                            <Dropdown
                                name={name}
                                options={options}
                                selected={selected[name]}
                                onChange={handleChange}
                                disabled={disabled}
                                disabledOptions={disabledOptions}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
};

export default VariationSelector;

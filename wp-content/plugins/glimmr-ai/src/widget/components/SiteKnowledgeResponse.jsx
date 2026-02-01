/**
 * SiteKnowledgeResponse - Site Knowledge Display Component
 *
 * Displays AI-generated responses from site knowledge base
 * with collapsible source citations.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useState, useMemo } from 'preact/hooks';

/**
 * Book/document icon.
 */
const BookIcon = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
    </svg>
);

/**
 * External link icon.
 */
const ExternalLinkIcon = () => (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
        <polyline points="15 3 21 3 21 9" />
        <line x1="10" y1="14" x2="21" y2="3" />
    </svg>
);

/**
 * Chevron icon.
 */
const ChevronIcon = ({ isExpanded }) => (
    <svg
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        className={`glimmr-knowledge-chevron ${isExpanded ? 'is-expanded' : ''}`}
    >
        <polyline points="6 9 12 15 18 9" />
    </svg>
);

/**
 * Info icon for tips/notes.
 */
const InfoIcon = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="12" cy="12" r="10" />
        <line x1="12" y1="16" x2="12" y2="12" />
        <line x1="12" y1="8" x2="12.01" y2="8" />
    </svg>
);

// S14: XSS Prevention - Escape HTML before markdown replacement
const escapeHtml = (text) => {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return text.replace(/[&<>"']/g, (char) => map[char]);
};

/**
 * Simple markdown-like text formatting.
 * S14: XSS Prevention - HTML is escaped before markdown transforms are applied.
 */
const formatText = (text) => {
    if (!text) return null;

    // Split into paragraphs
    const paragraphs = text.split(/\n\n+/);

    return paragraphs.map((paragraph, pIndex) => {
        // Check if it's a list
        const lines = paragraph.split('\n');
        const isList = lines.every((line) => /^[-*•]\s/.test(line.trim()));

        if (isList) {
            return (
                <ul key={pIndex} className="glimmr-knowledge-list">
                    {lines.map((line, lIndex) => (
                        <li key={lIndex}>{escapeHtml(line.replace(/^[-*•]\s/, ''))}</li>
                    ))}
                </ul>
            );
        }

        // Check for numbered list
        const isNumberedList = lines.every((line) => /^\d+[.)]\s/.test(line.trim()));

        if (isNumberedList) {
            return (
                <ol key={pIndex} className="glimmr-knowledge-list">
                    {lines.map((line, lIndex) => (
                        <li key={lIndex}>{escapeHtml(line.replace(/^\d+[.)]\s/, ''))}</li>
                    ))}
                </ol>
            );
        }

        // Regular paragraph with inline formatting
        // S14: ESCAPE FIRST, then apply markdown transforms
        const escapedParagraph = escapeHtml(paragraph);
        const formattedParagraph = escapedParagraph
            // Bold
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            // Code
            .replace(/`([^`]+)`/g, '<code>$1</code>');

        return (
            <p
                key={pIndex}
                className="glimmr-knowledge-paragraph"
                dangerouslySetInnerHTML={{ __html: formattedParagraph }}
            />
        );
    });
};

/**
 * Source citation component.
 */
const SourceCitation = ({ source, index }) => (
    <div className="glimmr-knowledge-source">
        <span className="glimmr-knowledge-source-number">{index + 1}</span>
        <div className="glimmr-knowledge-source-info">
            <span className="glimmr-knowledge-source-title">
                {source.title || 'Source'}
            </span>
            {source.type && (
                <span className="glimmr-knowledge-source-type">{source.type}</span>
            )}
        </div>
        {source.url && (
            <a
                href={source.url}
                target="_blank"
                rel="noopener noreferrer"
                className="glimmr-knowledge-source-link"
                aria-label={`View source: ${source.title}`}
            >
                <ExternalLinkIcon />
            </a>
        )}
    </div>
);

/**
 * Main SiteKnowledgeResponse component.
 */
const SiteKnowledgeResponse = ({
    content,
    sources = [],
    config = {},
    category,
    confidence,
}) => {
    const [isSourcesExpanded, setIsSourcesExpanded] = useState(false);

    // Get config values
    const {
        knowledgeShowSources = true,
        knowledgeMaxSources = 3,
    } = config.artifacts || config;

    /**
     * Get displayed sources (limited by config).
     */
    const displayedSources = useMemo(() => {
        if (!sources || !knowledgeShowSources) return [];
        return sources.slice(0, knowledgeMaxSources);
    }, [sources, knowledgeShowSources, knowledgeMaxSources]);

    const hasMoreSources = sources.length > knowledgeMaxSources;

    /**
     * Get confidence indicator.
     */
    const confidenceIndicator = useMemo(() => {
        if (!confidence) return null;

        if (confidence >= 0.8) {
            return { label: 'High confidence', className: 'high' };
        }
        if (confidence >= 0.5) {
            return { label: 'Medium confidence', className: 'medium' };
        }
        return { label: 'Low confidence', className: 'low' };
    }, [confidence]);

    if (!content) return null;

    return (
        <div className="glimmr-knowledge-response">
            {/* Category badge */}
            {category && (
                <div className="glimmr-knowledge-category">
                    <BookIcon />
                    <span>{category}</span>
                </div>
            )}

            {/* Main content */}
            <div className="glimmr-knowledge-content">
                {formatText(content)}
            </div>

            {/* Confidence indicator */}
            {confidenceIndicator && (
                <div className={`glimmr-knowledge-confidence glimmr-confidence-${confidenceIndicator.className}`}>
                    <InfoIcon />
                    <span>{confidenceIndicator.label}</span>
                </div>
            )}

            {/* Sources section */}
            {knowledgeShowSources && displayedSources.length > 0 && (
                <div className="glimmr-knowledge-sources-wrapper">
                    <button
                        type="button"
                        className="glimmr-knowledge-sources-toggle"
                        onClick={() => setIsSourcesExpanded(!isSourcesExpanded)}
                        aria-expanded={isSourcesExpanded}
                    >
                        <span>
                            {displayedSources.length} {displayedSources.length === 1 ? 'source' : 'sources'}
                        </span>
                        <ChevronIcon isExpanded={isSourcesExpanded} />
                    </button>

                    {isSourcesExpanded && (
                        <div className="glimmr-knowledge-sources">
                            {displayedSources.map((source, index) => (
                                <SourceCitation
                                    key={index}
                                    source={source}
                                    index={index}
                                />
                            ))}

                            {hasMoreSources && (
                                <div className="glimmr-knowledge-more-sources">
                                    +{sources.length - knowledgeMaxSources} more sources
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

/**
 * FAQ-style knowledge response.
 */
export const FAQResponse = ({
    question,
    answer,
    sources = [],
    config = {},
}) => {
    const [isExpanded, setIsExpanded] = useState(true);

    return (
        <div className="glimmr-faq-response">
            <button
                type="button"
                className="glimmr-faq-question"
                onClick={() => setIsExpanded(!isExpanded)}
                aria-expanded={isExpanded}
            >
                <span className="glimmr-faq-q">Q:</span>
                <span className="glimmr-faq-text">{question}</span>
                <ChevronIcon isExpanded={isExpanded} />
            </button>

            {isExpanded && (
                <div className="glimmr-faq-answer">
                    <span className="glimmr-faq-a">A:</span>
                    <SiteKnowledgeResponse
                        content={answer}
                        sources={sources}
                        config={config}
                    />
                </div>
            )}
        </div>
    );
};

export default SiteKnowledgeResponse;

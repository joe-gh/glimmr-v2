/**
 * ImageGallery - Product Image Gallery Component
 *
 * Displays product images with navigation and zoom functionality.
 * Supports both gallery (thumbnails) and single image modes.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useCallback, useRef, useEffect } from 'preact/hooks';

/**
 * Left arrow icon.
 */
const ChevronLeft = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <polyline points="15 18 9 12 15 6" />
    </svg>
);

/**
 * Right arrow icon.
 */
const ChevronRight = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <polyline points="9 18 15 12 9 6" />
    </svg>
);

/**
 * Main ImageGallery component.
 */
const ImageGallery = ({
    images = [],
    style = 'gallery', // 'gallery' | 'single'
    showThumbnails = true,
    showArrows = true,
    showDots = true,
    autoPlay = false,
    autoPlayInterval = 5000,
    onImageClick,
}) => {
    const [currentIndex, setCurrentIndex] = useState(0);
    const [isZoomed, setIsZoomed] = useState(false);
    const [touchStart, setTouchStart] = useState(null);
    const galleryRef = useRef(null);
    const autoPlayRef = useRef(null);

    // Normalize images to array of objects
    const normalizedImages = images.map((img) =>
        typeof img === 'string'
            ? { src: img, alt: 'Product image' }
            : { src: img.src || img.url || img, alt: img.alt || 'Product image' }
    );

    // Single image mode just shows the first image
    if (style === 'single' || normalizedImages.length <= 1) {
        return (
            <div className="glimmr-gallery glimmr-gallery-single">
                <div
                    className="glimmr-gallery-main"
                    onClick={() => onImageClick && onImageClick(0)}
                >
                    {normalizedImages.length > 0 ? (
                        <img
                            src={normalizedImages[0].src}
                            alt={normalizedImages[0].alt}
                            className="glimmr-gallery-image"
                        />
                    ) : (
                        <div className="glimmr-gallery-placeholder">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                                <circle cx="8.5" cy="8.5" r="1.5" />
                                <polyline points="21 15 16 10 5 21" />
                            </svg>
                        </div>
                    )}
                </div>
            </div>
        );
    }

    /**
     * Navigate to specific slide.
     */
    const goToSlide = useCallback((index) => {
        let newIndex = index;
        if (index < 0) newIndex = normalizedImages.length - 1;
        if (index >= normalizedImages.length) newIndex = 0;
        setCurrentIndex(newIndex);
    }, [normalizedImages.length]);

    /**
     * Go to next slide.
     */
    const nextSlide = useCallback(() => {
        goToSlide(currentIndex + 1);
    }, [currentIndex, goToSlide]);

    /**
     * Go to previous slide.
     */
    const prevSlide = useCallback(() => {
        goToSlide(currentIndex - 1);
    }, [currentIndex, goToSlide]);

    /**
     * Handle keyboard navigation.
     */
    const handleKeyDown = useCallback((e) => {
        if (e.key === 'ArrowLeft') {
            prevSlide();
        } else if (e.key === 'ArrowRight') {
            nextSlide();
        } else if (e.key === 'Escape' && isZoomed) {
            setIsZoomed(false);
        }
    }, [prevSlide, nextSlide, isZoomed]);

    /**
     * Handle touch start for swipe.
     */
    const handleTouchStart = useCallback((e) => {
        setTouchStart(e.touches[0].clientX);
    }, []);

    /**
     * Handle touch end for swipe.
     */
    const handleTouchEnd = useCallback((e) => {
        if (!touchStart) return;

        const touchEnd = e.changedTouches[0].clientX;
        const diff = touchStart - touchEnd;

        // Minimum swipe distance
        if (Math.abs(diff) > 50) {
            if (diff > 0) {
                nextSlide();
            } else {
                prevSlide();
            }
        }

        setTouchStart(null);
    }, [touchStart, nextSlide, prevSlide]);

    /**
     * Auto-play functionality.
     */
    useEffect(() => {
        if (autoPlay && normalizedImages.length > 1) {
            autoPlayRef.current = setInterval(nextSlide, autoPlayInterval);
            return () => clearInterval(autoPlayRef.current);
        }
    }, [autoPlay, autoPlayInterval, nextSlide, normalizedImages.length]);

    /**
     * Pause auto-play on hover.
     */
    const handleMouseEnter = useCallback(() => {
        if (autoPlayRef.current) {
            clearInterval(autoPlayRef.current);
        }
    }, []);

    const handleMouseLeave = useCallback(() => {
        if (autoPlay && normalizedImages.length > 1) {
            autoPlayRef.current = setInterval(nextSlide, autoPlayInterval);
        }
    }, [autoPlay, autoPlayInterval, nextSlide, normalizedImages.length]);

    return (
        <div
            ref={galleryRef}
            className={`glimmr-gallery ${isZoomed ? 'is-zoomed' : ''}`}
            onKeyDown={handleKeyDown}
            onMouseEnter={handleMouseEnter}
            onMouseLeave={handleMouseLeave}
            tabIndex="0"
            role="region"
            aria-label="Product image gallery"
        >
            {/* Main image display */}
            <div
                className="glimmr-gallery-main"
                onTouchStart={handleTouchStart}
                onTouchEnd={handleTouchEnd}
            >
                <img
                    src={normalizedImages[currentIndex].src}
                    alt={normalizedImages[currentIndex].alt}
                    className="glimmr-gallery-image"
                    onClick={() => onImageClick && onImageClick(currentIndex)}
                />

                {/* Navigation arrows */}
                {showArrows && normalizedImages.length > 1 && (
                    <>
                        <button
                            type="button"
                            className="glimmr-gallery-arrow glimmr-gallery-arrow-left"
                            onClick={prevSlide}
                            aria-label="Previous image"
                        >
                            <ChevronLeft />
                        </button>
                        <button
                            type="button"
                            className="glimmr-gallery-arrow glimmr-gallery-arrow-right"
                            onClick={nextSlide}
                            aria-label="Next image"
                        >
                            <ChevronRight />
                        </button>
                    </>
                )}

                {/* Image counter */}
                <div className="glimmr-gallery-counter">
                    {currentIndex + 1} / {normalizedImages.length}
                </div>
            </div>

            {/* Dot indicators */}
            {showDots && normalizedImages.length > 1 && (
                <div className="glimmr-gallery-dots" role="tablist">
                    {normalizedImages.map((_, index) => (
                        <button
                            key={index}
                            type="button"
                            className={`glimmr-gallery-dot ${index === currentIndex ? 'is-active' : ''}`}
                            onClick={() => goToSlide(index)}
                            role="tab"
                            aria-selected={index === currentIndex}
                            aria-label={`Go to image ${index + 1}`}
                        />
                    ))}
                </div>
            )}

            {/* Thumbnail strip */}
            {showThumbnails && normalizedImages.length > 1 && (
                <div className="glimmr-gallery-thumbnails">
                    {normalizedImages.map((image, index) => (
                        <button
                            key={index}
                            type="button"
                            className={`glimmr-gallery-thumb ${index === currentIndex ? 'is-active' : ''}`}
                            onClick={() => goToSlide(index)}
                            aria-label={`View image ${index + 1}`}
                        >
                            <img src={image.src} alt="" />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

export default ImageGallery;

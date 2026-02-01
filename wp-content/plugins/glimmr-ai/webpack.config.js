/**
 * Webpack Configuration for Glimmr AI
 *
 * Builds admin React components (using WordPress React) and
 * frontend Preact widget (standalone bundle).
 *
 * @package Glimmr_AI
 */

const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = (env, argv) => {
    const isProduction = argv.mode === 'production';

    // Shared settings
    const commonSettings = {
        output: {
            path: path.resolve(__dirname),
        },

        module: {
            rules: [
                // SCSS/CSS (shared)
                {
                    test: /\.(scss|css)$/,
                    use: [
                        MiniCssExtractPlugin.loader,
                        'css-loader',
                        {
                            loader: 'postcss-loader',
                            options: {
                                postcssOptions: {
                                    plugins: ['autoprefixer'],
                                },
                            },
                        },
                        'sass-loader',
                    ],
                },

                // Images (shared)
                {
                    test: /\.(png|jpg|jpeg|gif|svg)$/,
                    type: 'asset/resource',
                    generator: {
                        filename: 'assets/images/[name][ext]',
                    },
                },
            ],
        },

        plugins: [
            new MiniCssExtractPlugin({
                filename: '[name].css',
            }),
        ],

        devtool: isProduction ? 'source-map' : 'eval-source-map',

        optimization: {
            minimize: isProduction,
        },

        performance: {
            hints: isProduction ? 'warning' : false,
            maxAssetSize: 512000,
            maxEntrypointSize: 512000,
        },

        stats: {
            colors: true,
            modules: false,
        },
    };

    // Admin configuration (uses WordPress React)
    const adminConfig = {
        ...commonSettings,
        name: 'admin',
        entry: {
            'admin/js/glimmr-ai-admin-bundle': './src/admin/index.js',
        },
        output: {
            ...commonSettings.output,
            filename: '[name].js',
        },
        module: {
            rules: [
                // JavaScript/JSX for admin (uses WordPress React externals)
                {
                    test: /\.(js|jsx)$/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: {
                            presets: [
                                '@babel/preset-env',
                                ['@babel/preset-react', {
                                    runtime: 'classic',
                                }],
                            ],
                        },
                    },
                },
                ...commonSettings.module.rules,
            ],
        },
        plugins: [
            new MiniCssExtractPlugin({
                filename: '[name].css',
            }),
        ],
        resolve: {
            extensions: ['.js', '.jsx'],
        },
        externals: {
            // Use WordPress's React for admin
            'react': 'React',
            'react-dom': 'ReactDOM',
            '@wordpress/element': 'wp.element',
            '@wordpress/components': 'wp.components',
            'jquery': 'jQuery',
        },
    };

    // Widget configuration (uses Preact - standalone bundle)
    const widgetConfig = {
        ...commonSettings,
        name: 'widget',
        entry: {
            'public/js/glimmr-ai-widget-bundle': './src/widget/index.js',
        },
        output: {
            ...commonSettings.output,
            filename: '[name].js',
        },
        module: {
            rules: [
                // JavaScript/JSX for widget (Preact with h pragma)
                {
                    test: /\.(js|jsx)$/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: {
                            presets: [
                                '@babel/preset-env',
                                ['@babel/preset-react', {
                                    pragma: 'h',
                                    pragmaFrag: 'Fragment',
                                }],
                            ],
                        },
                    },
                },
                ...commonSettings.module.rules,
            ],
        },
        plugins: [
            new MiniCssExtractPlugin({
                filename: '[name].css',
            }),
        ],
        resolve: {
            extensions: ['.js', '.jsx'],
            alias: {
                // Use Preact for the widget (smaller bundle)
                'react': 'preact/compat',
                'react-dom': 'preact/compat',
            },
        },
        // No externals - bundle everything for the widget
        externals: {},
    };

    // Return both configurations for parallel building
    return [adminConfig, widgetConfig];
};

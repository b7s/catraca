.PHONY: help install check test test-unit test-types test-coverage test-plugin release

RELEASE_VERSION := $(if $(VERSION),$(VERSION),$(version))
RELEASE_MESSAGE := $(if $(MESSAGE),$(MESSAGE),$(message))

# Default target
help:
	@echo "Available commands:"
	@echo "  make install         - Install dependencies"
	@echo "  make check           - Run quality gates (./bin/catraca)"
	@echo "  make release version=x.y.z message='msg' - Run quality gates and create Git tag"
	@echo "  make clean           - Clean cache and temporary files"

# Install dependencies
install:
	@echo "📦 Installing dependencies..."
	composer install
	@echo "✅ Installation complete!"

# Run quality gates
check:
	@echo "🔍 Running quality gates..."
	@./bin/catraca

# Create a tagged release (auto-commits changes if any)
release: check
	@if [ -f version ]; then \
		LAST_VERSION=$$(cat version); \
		echo "📌 Last version: v$$LAST_VERSION"; \
		echo ""; \
	fi; \
	VERSION_INPUT="$(RELEASE_VERSION)"; \
	if [ -z "$$VERSION_INPUT" ]; then \
		read -p "Enter release version (format x.y.z): " VERSION_INPUT; \
	fi; \
	if ! echo "$$VERSION_INPUT" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$$'; then \
		echo "❌ Invalid version format. Expected x.y.z (e.g., 1.3.0)"; exit 1; \
	fi; \
	echo "📦 New version: v$$VERSION_INPUT"; \
	MESSAGE_INPUT="$(RELEASE_MESSAGE)"; \
	if [ -z "$$MESSAGE_INPUT" ]; then \
		echo "Enter release message (press Enter for default, Ctrl+D when done for multi-line):"; \
		MESSAGE_INPUT=$$(cat); \
		if [ -z "$$MESSAGE_INPUT" ]; then \
			MESSAGE_INPUT="Release v$$VERSION_INPUT"; \
		fi; \
	fi; \
	echo "🔍 Checking for uncommitted changes..."; \
	if ! git diff --quiet || ! git diff --cached --quiet; then \
		echo "📝 Found uncommitted changes. Staging files..."; \
		git add -A; \
		echo "💾 Creating commit..."; \
		git commit -m "$$MESSAGE_INPUT" || true; \
	else \
		echo "✅ Working tree is clean."; \
	fi; \
	echo "🚀 Pushing commits to origin..."; \
	if git push origin HEAD; then \
		echo "✅ Push successful!"; \
	else \
		echo "⚠️  No commits to push (working tree was clean)"; \
	fi; \
	echo "🏷️  Creating tag v$$VERSION_INPUT..."; \
	git tag -a v$$VERSION_INPUT -m "$$MESSAGE_INPUT"; \
	echo "🚀 Pushing tag to origin..."; \
	if git push origin v$$VERSION_INPUT; then \
		echo "✅ Tag pushed successfully!"; \
		echo "📝 Updating version file..."; \
		echo "$$VERSION_INPUT" > version; \
		git add version; \
		git commit -m "Update version to $$VERSION_INPUT" || true; \
		git push origin HEAD || true; \
	else \
		echo "❌ Failed to push tag"; \
		exit 1; \
	fi; \
	echo ""; \
	echo "✅ Release v$$VERSION_INPUT created successfully!"; \
	echo "📦 Packagist will automatically detect the new version."; \
	echo "🔗 View release: https://github.com/b7s/catraca/releases/tag/v$$VERSION_INPUT"

# Clean cache and temporary files
clean:
	@echo "🧹 Cleaning cache and temporary files..."
	rm -rf build/
	rm -rf vendor/
	rm -rf .phpunit.cache/
	@echo "✅ Clean complete!"

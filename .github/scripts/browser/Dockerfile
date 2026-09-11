# Playwright 1.63.0 noble, linux/amd64 (ubuntu-24.04 runner).
# https://github.com/microsoft/playwright/releases/tag/v1.63.0
# Browsers and driver must match; update package-lock.json with this pin.
FROM mcr.microsoft.com/playwright@sha256:bc6ab0d6d44ff4826e4cb8c1e6d801e185bfc42bb0753f8e2a30efc70db054c7
WORKDIR /opt/browser
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY run-fixture.cjs ./
USER pwuser
ENTRYPOINT ["node", "/opt/browser/run-fixture.cjs"]

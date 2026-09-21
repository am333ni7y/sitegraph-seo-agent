# Contributing to wordpress-mcp

Thank you for your interest in contributing to **wordpress-mcp**! We welcome bug fixes, documentation improvements, and new feature tools.

## Development Setup

1. Fork and clone the repository:
   ```bash
   git clone https://github.com/am333ni7y/wordpress-mcp.git
   cd wordpress-mcp
   ```

2. Install dependencies:
   ```bash
   npm install
   ```

3. Build TypeScript in watch mode:
   ```bash
   npm run dev
   ```

4. Run tests:
   ```bash
   npm test
   ```

## Contribution Guidelines

- **Zero-Trust Security**: Ensure any new tools that mutate state respect `security.assertWritable()`.
- **Input Validation**: Strictly validate inputs with Zod schemas.
- **Type Safety**: Maintain clean TypeScript compilation with no `any` leaks in public APIs.
- **Pull Requests**:
  - Keep PRs focused on a single change.
  - Include tests or manual verification steps.
  - Update `README.md` and `README.fa.md` if adding new tools or changing parameters.

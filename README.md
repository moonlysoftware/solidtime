# solidtime - The modern Open-Source TimeTracker

[![GitHub License](https://img.shields.io/github/license/solidtime-io/solidtime?style=flat-square)](https://github.com/solidtime-io/solidtime/blob/main/LICENSE.md)
[![Codecov](https://img.shields.io/codecov/c/github/solidtime-io/solidtime?style=flat-square&logo=codecov)](https://codecov.io/gh/solidtime-io/solidtime)
![GitHub Actions Unit Tests Status](https://img.shields.io/github/actions/workflow/status/solidtime-io/solidtime/phpunit.yml?style=flat-square)
![PHPStan badge](https://img.shields.io/badge/PHPStan-Level_7-blue?style=flat-square&color=blue)

![Screenshot of the solidtime application with header: solidtime - The modern Open-Source Time Tracker](docs/solidtime-banner.png "solidtime Banner")

solidtime is a modern open-source time tracking application for Freelancers and Agencies.

## Features

 - Time tracking: Track your time with a modern and easy-to-use interface
 - Projects: Create and manage projects and assign project members
 - Tasks: Create and manage tasks and assign tasks to projects
 - Clients: Create and manage clients and assign clients to projects
 - Billable rates: Set billable rates for projects, project members, organization members and organizations 
 - Multiple organizations: Create and manage multiple organizations with one account
 - Roles and permissions: Create and manage organizations
 - Import: Import your time tracking data from other time tracking applications (Supported: Toggl, Clockify, Timeentry CSV)

## Self Hosting

If you are looking into self-hosting solidtime, you can find the guides [here](https://docs.solidtime.io/self-hosting/intro)

We also have an examples repository [here](https://github.com/solidtime-io/self-hosting-examples)

If you do not want to self-host solidtime or try it out you can sign up for [solidtime cloud](https://www.solidtime.io/)

## Issues & Feature Requests

If you 
* find any **bugs in solidtime**, please feel free to [**open an issue**](https://github.com/solidtime-io/solidtime/issues/new) in this repository, with instructions on how to reproduce the bug. 
* have a **feature request**, please [**create a discussion**](https://github.com/solidtime-io/solidtime/discussions/new?category=feature-requests) in this repository.


> **Do not use AI-generated text in issues, discussions and PR descriptions.**
>
> We are human, so please write in natural language in your issues, discussions, and PR descriptions.
> Don't be a meatproxy. We will ban people who repeatedly violate this rule from further communication on our repositories.


## Contributing

Please open an issue or start a discussion and wait for approval before submitting a pull request. This does not apply to tiny fixes or changes however, please keep in mind that we might not merge PRs for various reasons. 

**If you submit an AI slop pull request (especially without following the proper procedure), you will be banned from future contributions to solidtime.**

To keep that manageable, pull requests from authors who are not vouched are closed automatically, unless they change 50 lines or fewer. To get vouched, open an issue or discussion first and explain how you intend to implement the change. Once we have agreed on the approach, we vouch for you. See [Vouched contributors](./CONTRIBUTING.md#vouched-contributors).

Please read the [CONTRIBUTING.md](./CONTRIBUTING.md) before sumbitting a Pull Request.

We do accept contributions in the [documentation repository](https://github.com/solidtime-io/docs) f.e. to add new self-hosting guides.

## Security

Looking to report a vulnerability? Please refer our [SECURITY.md](./SECURITY.md) file.

## License

This project is open-source and available under the GNU Affero General Public License v3.0 (AGPL v3). Please see the [license file](LICENSE.md) for more information.

### Project and task suggestions

Suggestions use existing project/task/client names. Vectors live in a separate
`project_task_embeddings` table; existing model fields are unchanged.
Configure `GATEWAY_URL`, `GATEWAY_API_KEY`, and `GATEWAY_EMBEDDING_MODEL` (default:
`qwen3-embedding-8b`). The gateway must provide an OpenAI-compatible `/embeddings` endpoint.
Only candidate text and the entry description are sent; historical entries are not used.

The database needs pgvector 0.8+ with HNSW support. The local and CI PostgreSQL images include it;
for an existing production database, install pgvector before running `php artisan migrate`.
The migration enables the extension, so its database user needs permission to do that.

Use an asynchronous `QUEUE_CONNECTION` and a running `php artisan queue:work` worker.
Run `php artisan embeddings:index` once to verify model access and queue existing projects
and tasks. Laravel's scheduler runs `embeddings:index --recent` hourly, scanning
projects, tasks and clients updated in the last hour using `updated_at` indexes.
An input hash and model check skip unchanged embeddings before calling the gateway.
Project/client name changes also refresh the affected tasks. Keep Laravel's scheduler running.
If the scheduler misses an hour, run `php artisan embeddings:index` to catch up;
unchanged records are skipped.
The command can also rebuild all vectors after changing the model.

Opening an entry's project/task picker shows up to five suggestions. Nothing is selected
automatically, and the regular picker remains available if the gateway is unavailable.

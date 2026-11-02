import axios from 'axios';
import { getCurrentOrganizationId } from '@/utils/useUser';
import type { ProjectTaskSuggestion } from '@/packages/ui/src/TimeTracker/projectTaskSuggestions';

export async function suggestProjectsAndTasks(
    description: string,
    excludeTimeEntryId?: string | null
): Promise<ProjectTaskSuggestion[]> {
    const organization = getCurrentOrganizationId();
    if (!organization) return [];
    const response = await axios.post<{ data: ProjectTaskSuggestion[] }>(
        `/api/v1/organizations/${organization}/project-task-suggestions`,
        { description, exclude_time_entry_id: excludeTimeEntryId || undefined },
        { timeout: 6000 }
    );
    // Do not display results requested for a previously selected organization.
    return getCurrentOrganizationId() === organization ? response.data.data : [];
}

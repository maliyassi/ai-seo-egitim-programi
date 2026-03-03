import pandas as pd
import networkx as nx

# Path to the CSV file
csv_path = 'source_destination - all_ınlinks.csv'

try:
    print(f"Reading CSV file: {csv_path}...")
    # Read the CSV file
    df = pd.read_csv(csv_path)

    # Filter for standard 'Hyperlink' rows if the 'Type' column exists
    if 'Type' in df.columns:
        initial_count = len(df)
        df = df[df['Type'] == 'Hyperlink']
        print(f"Filtered to {len(df)} Hyperlink rows (from {initial_count}).")

    # Create a directed graph from the Source and Destination columns
    print("Building directed graph...")
    G = nx.from_pandas_edgelist(
        df, 
        source='Source', 
        target='Destination', 
        create_using=nx.DiGraph()
    )
    
    # Calculate PageRank
    print("Calculating Internal PageRank...")
    pagerank_scores = nx.pagerank(G, alpha=0.85)
    
    # Create a DataFrame from the results
    pr_df = pd.DataFrame(list(pagerank_scores.items()), columns=['URL', 'Internal PageRank'])
    
    # Sort by PageRank score in descending order
    pr_df = pr_df.sort_values(by='Internal PageRank', ascending=False)
    
    # Save the report to a new CSV file
    output_path = 'internal_pagerank_report.csv'
    pr_df.to_csv(output_path, index=False)
    
    print(f"Success! Internal PageRank report has been saved to '{output_path}'.")
    print("\nTop 10 URLs with highest Internal PageRank:")
    print(pr_df.head(10).to_string(index=False))

except Exception as e:
    print(f"An error occurred: {e}")
